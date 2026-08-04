<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Services;

use App\Core\Application\Services\BaseService;
use App\Models\User;
use App\Modules\Identity\Application\Actions\ChangePasswordAction;
use App\Modules\Identity\Application\Actions\LoginAction;
use App\Modules\Identity\Application\Actions\LogoutAction;
use App\Modules\Identity\Application\Actions\RegisterUserAction;
use App\Modules\Identity\Domain\Contracts\LoginAttemptRepositoryContract;
use App\Modules\Identity\Domain\Contracts\UserRepositoryContract;
use App\Modules\Identity\Domain\DTOs\LoginDTO;
use App\Modules\Identity\Domain\DTOs\RegisterUserDTO;
use App\Modules\Identity\Domain\Events\SuspiciousLoginDetected;
use App\Modules\Identity\Domain\Events\UserLoggedOut;
use App\Modules\Identity\Domain\Exceptions\AuthenticationFailed;
use App\Modules\Identity\Domain\Models\UserSession;
use App\Shared\Enums\LoginThreatKind;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The authentication API the rest of the platform calls.
 *
 * Controllers, Filament panels and console commands go through this; none of
 * them touch `Auth::attempt()` directly. That matters because every path into
 * authentication has to produce the same side effects — an attempt row, a
 * session row, a device row, a domain event — and a second entry point is a
 * second place to forget one.
 *
 * The service ORCHESTRATES; the actions do the work and own the transactions.
 *
 * @see docs/001_Architecture.md §3
 *
 * @extends BaseService<User>
 */
final class AuthService extends BaseService
{
    protected string $logChannel = 'audit';

    public function __construct(
        private readonly LoginAction $login,
        private readonly LogoutAction $logout,
        private readonly RegisterUserAction $register,
        private readonly ChangePasswordAction $changePassword,
        private readonly SessionService $sessions,
        private readonly LoginAttemptRepositoryContract $attempts,
        private readonly UserRepositoryContract $users,
    ) {}

    /**
     * Authenticate. Throws AuthenticationFailed on every failure path.
     *
     * On failure, evaluates whether the address is under attack and raises
     * SuspiciousLoginDetected before rethrowing. The check runs AFTER the
     * action has recorded its attempt row, so the current failure is counted;
     * it never changes the outcome the caller sees — a detected attack still
     * throws the same AuthenticationFailed.
     *
     * @throws AuthenticationFailed
     */
    public function attempt(LoginDTO $data, Request $request): UserSession
    {
        try {
            return $this->login->run($data, $request);
        } catch (AuthenticationFailed $failure) {
            $this->flagIfUnderAttack($data, $request);

            throw $failure;
        }
    }

    public function signOut(User $user, Request $request, ?string $guard = null): void
    {
        $this->logout->run($user, $request, $guard ?? $user->guardName(), UserLoggedOut::REASON_MANUAL);
    }

    public function registerUser(RegisterUserDTO $data): User
    {
        return $this->register->run($data);
    }

    /**
     * Change a password and evict every other session.
     *
     * `$keepCurrentSession` defaults to true — logging a user out of the tab
     * they just used is hostile and self-defeating.
     */
    public function updatePassword(
        User $user,
        string $newPassword,
        ?Request $request = null,
        bool $viaReset = false,
        bool $keepCurrentSession = true,
    ): User {
        $keepSessionId = $keepCurrentSession && $request?->hasSession() === true
            ? $request->session()->getId()
            : null;

        return $this->changePassword->run($user, $newPassword, $keepSessionId, $viaReset);
    }

    /**
     * Classify a run of failures against one address, or return null when it is
     * still within the noise a forgetful user produces.
     *
     * Distinct from the rate limiter, which throttles on a sliding window and
     * forgets. This answers "has someone been grinding at this account, and how?"
     * — and which answer decides how severely the forensic trail grades it.
     *
     * Credential stuffing (many source IPs) is checked first: it is the more
     * serious signal, and a botnet also trips the raw failure count, so testing
     * brute force first would mislabel every stuffing run.
     */
    public function classifyThreat(string $email, string $guard): ?LoginThreatKind
    {
        $window = (int) config('marketplace.security.suspicious_login.window_minutes', 60);

        $failures = $this->attempts->recentFailuresFor($email, $guard, $window);
        $distinctIps = $this->attempts->distinctIpsFor($email, $window);

        $stuffingFailures = (int) config('marketplace.security.suspicious_login.stuffing_failures', 5);
        $stuffingIps = (int) config('marketplace.security.suspicious_login.stuffing_distinct_ips', 3);
        $bruteForce = (int) config('marketplace.security.suspicious_login.brute_force_failures', 10);

        if ($failures >= $stuffingFailures && $distinctIps >= $stuffingIps) {
            return LoginThreatKind::CredentialStuffing;
        }

        if ($failures >= $bruteForce) {
            return LoginThreatKind::BruteForce;
        }

        return null;
    }

    /**
     * Sessions the user can see and revoke on their security page.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, UserSession>
     */
    public function activeSessions(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return $this->sessions->activeFor($user);
    }

    /**
     * "Sign out everywhere else."
     */
    public function signOutOtherDevices(User $user, Request $request): int
    {
        return $this->sessions->revokeAll(
            user: $user,
            reason: UserLoggedOut::REASON_ALL_DEVICES,
            exceptSessionId: $request->hasSession() ? $request->session()->getId() : null,
            actor: $user,
        );
    }

    /**
     * After a failed login, raise SuspiciousLoginDetected if the address has
     * crossed the threshold — but at most once per cooldown, so a sustained
     * attack produces one alert per window, not one per attempt.
     *
     * The cooldown flag is claimed atomically (`Cache::add`): concurrent
     * failures cannot both win it and double-notify.
     *
     * NEVER ALLOWED TO BREAK THE LOGIN. Detection is a side effect on the
     * failure path; a fault here — a cache outage, a listener throwing — must
     * not turn an ordinary wrong-password into a 500. It is swallowed and
     * reported, exactly as the attempt-logging is.
     */
    private function flagIfUnderAttack(LoginDTO $data, Request $request): void
    {
        try {
            $email = $data->normalisedEmail();
            $guard = $data->guard();

            $kind = $this->classifyThreat($email, $guard);

            if ($kind === null) {
                return;
            }

            $cooldown = (int) config('marketplace.security.suspicious_login.alert_cooldown_minutes', 60);
            $lock = 'suspicious_login:'.$guard.':'.sha1($email);

            // First failure past the threshold in this window claims the slot;
            // later ones find it taken and stay quiet.
            if (! Cache::add($lock, true, now()->addMinutes(max(1, $cooldown)))) {
                return;
            }

            $window = (int) config('marketplace.security.suspicious_login.window_minutes', 60);

            // Resolve the owner if the address is a real account of this type — a
            // stuffing run hits addresses that were never registered, and those
            // have no owner to warn.
            $user = $this->users->findByEmailForType($email, $data->type);

            SuspiciousLoginDetected::dispatch(
                $kind,
                $email,
                $guard,
                $this->attempts->recentFailuresFor($email, $guard, $window),
                $this->attempts->distinctIpsFor($email, $window),
                $request->ip(),
                $user?->getKey(),
                $user?->uuid,
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
