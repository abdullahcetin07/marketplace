<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Models\User;
use App\Modules\Identity\Application\Services\SessionService;
use App\Modules\Identity\Application\Services\TwoFactorService;
use App\Modules\Identity\Domain\Contracts\LoginAttemptRepositoryContract;
use App\Modules\Identity\Domain\Contracts\UserRepositoryContract;
use App\Modules\Identity\Domain\DTOs\LoginDTO;
use App\Modules\Identity\Domain\Events\UserLoggedIn;
use App\Modules\Identity\Domain\Exceptions\AuthenticationFailed;
use App\Modules\Identity\Domain\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Authenticate a user against one specific guard.
 *
 * THE THREE THINGS THIS GETS RIGHT, EACH OF WHICH IS EASY TO GET WRONG:
 *
 * 1. **No account enumeration.** A missing account, a wrong password and a
 *    suspended account all produce the same response. The real reason is
 *    recorded on the attempt row for detection, never returned to the caller.
 *
 *    @see AuthenticationFailed
 *
 * 2. **No timing oracle.** When the address does not exist we still run a
 *    bcrypt comparison against a dummy hash. Without it, "no such user"
 *    returns in ~1ms and "wrong password" in ~100ms, which is a reliable
 *    enumeration channel regardless of what the response body says.
 *
 * 3. **Every attempt is recorded, including failures.** A run of forty
 *    failures against one address from twelve IPs is credential stuffing, and
 *    the login_attempts table is the only place that is visible.
 *
 * Transaction is OFF: this action writes an attempt row that must survive
 * even when authentication fails and an exception unwinds the call. Rolling it
 * back would erase exactly the evidence the table exists to keep.
 * @see docs/authentication.md
 */
final class LoginAction extends BaseAction
{
    /**
     * A valid bcrypt hash of a value nothing will ever match. Used to burn the
     * same CPU time on a missing account as on a real one.
     */
    private const string DUMMY_HASH = '$2y$12$sJ8Q4nqXH0jbYjJ0eKQ0uOaG3H1s0YQ5jP5nZ8wQ7yG4L1mN2oP6a';

    protected bool $useTransaction = false;

    public function __construct(
        private readonly SessionService $sessions,
        private readonly TwoFactorService $twoFactor,
        private readonly UserRepositoryContract $users,
        private readonly LoginAttemptRepositoryContract $attempts,
    ) {}

    /**
     * @throws AuthenticationFailed
     */
    public function handle(mixed ...$arguments): UserSession
    {
        /** @var LoginDTO $data */
        [$data, $request] = [$arguments[0], $arguments[1]];
        /** @var Request $request */
        $user = $this->findUser($data);

        try {
            $this->verifyPassword($user, $data->password);
            $this->verifyAccountState($user);
            $this->verifyTwoFactor($user, $data);
        } catch (AuthenticationFailed $failure) {
            $this->recordAttempt($data, $request, $user, $failure->reason());

            throw $failure;
        }

        // Checked BEFORE the session is created, or every login looks new.
        $isNewDevice = $this->sessions->isNewDevice($user, $request);

        Auth::guard($data->guard())->login($user, $data->remember);

        // Regenerate on privilege change — otherwise a session id captured
        // before login remains valid after it (session fixation).
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $session = $this->sessions->start($user, $request, $data->guard());

        if ($data->trustDevice && $session->device !== null) {
            $session->device->trust();
        }

        // recordLogin() is NOT called here. The Login listener in
        // EventServiceProvider owns the stamp (docs/logging.md) and the guard
        // login above already fired that event — calling it here too counted
        // every sign-in twice. The listener also covers the sign-ins that never
        // reach this action, such as the Filament panels.
        $this->recordAttempt($data, $request, $user, null);

        return $session;
    }

    /**
     * After-commit hook — announces the login once everything above succeeded.
     */
    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var UserSession $result */
        /** @var LoginDTO $data */
        $data = $arguments[0];

        UserLoggedIn::dispatch(
            $result->user_id,
            $result->user->uuid,
            $data->guard(),
            $result->ip_address,
            $result->device_id,
            // The device row was created moments ago if it is new.
            $result->device !== null && $result->device->wasRecentlyCreated,
        );
    }

    /**
     * Resolve through the TYPE-SCOPED model, not the base User.
     *
     * `UserType::Seller->model()` returns App\Models\Seller, whose global scope
     * is `where type = 'seller'`. That is what makes a seller posting to the
     * admin login form indistinguishable from a non-existent account — the
     * query cannot see the row.
     */
    private function findUser(LoginDTO $data): ?User
    {
        return $this->users->findByEmailForType($data->normalisedEmail(), $data->type);
    }

    /**
     * @throws AuthenticationFailed
     */
    private function verifyPassword(?User $user, string $password): void
    {
        if ($user === null) {
            // Burn equivalent time. @see class docblock point 2.
            Hash::check($password, self::DUMMY_HASH);

            throw AuthenticationFailed::invalidCredentials();
        }

        if (! Hash::check($password, $user->password)) {
            throw AuthenticationFailed::invalidCredentials();
        }
    }

    /**
     * Runs only after the password is proven correct, so these checks cannot
     * leak anything about an account the caller has not authenticated to.
     *
     * @throws AuthenticationFailed
     */
    private function verifyAccountState(User $user): void
    {
        if (! $user->canAuthenticate()) {
            throw AuthenticationFailed::suspended();
        }

        // Only enforced where the guard demands it. A customer browsing with an
        // unverified address is normal; an admin with one is not.
        if ($user->type->isStaff() && $user->email_verified_at === null) {
            throw AuthenticationFailed::unverified();
        }
    }

    /**
     * @throws AuthenticationFailed
     */
    private function verifyTwoFactor(User $user, LoginDTO $data): void
    {
        if ($this->twoFactor->isRequiredFor($user)) {
            // Enrolment is mandatory but not yet done — the caller must be
            // routed into enrolment, not simply refused.
            throw AuthenticationFailed::because('two_factor_enrolment_required')->withStatus(403);
        }

        if (! $user->hasTwoFactorEnabled()) {
            return;
        }

        if (blank($data->twoFactorCode)) {
            throw AuthenticationFailed::twoFactorRequired();
        }

        if (! $this->twoFactor->verify($user, $data->twoFactorCode)) {
            throw AuthenticationFailed::twoFactorInvalid();
        }
    }

    /**
     * Write the attempt row.
     *
     * Never allowed to break the login: a logging failure must not turn a valid
     * sign-in into a 500. Swallowed and reported, not rethrown.
     */
    private function recordAttempt(
        LoginDTO $data,
        Request $request,
        ?User $user,
        ?string $failureReason,
    ): void {
        $agent = $this->parseUserAgent($request->userAgent());

        // The repository swallows and reports its own failures — a logging
        // error must never turn a valid sign-in into a 500.
        $this->attempts->record([
            'user_id' => $user?->getKey(),
            'email' => $data->normalisedEmail(),
            'guard' => $data->guard(),
            'successful' => $failureReason === null,
            'failure_reason' => $failureReason,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
            'platform' => $agent['platform'],
            'browser' => $agent['browser'],
        ]);
    }

    /**
     * Crude UA classification for the attempt row.
     *
     * Deliberately not a parsing library: this is read by a human reviewing a
     * suspicious login, and a UA parser is a dependency needing constant
     * updates to stay accurate.
     *
     * @return array{browser: string|null, platform: string|null}
     */
    private function parseUserAgent(?string $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return ['browser' => null, 'platform' => null];
        }

        return [
            'browser' => match (true) {
                str_contains($raw, 'Edg/') => 'Edge',
                str_contains($raw, 'OPR/') => 'Opera',
                str_contains($raw, 'Firefox/') => 'Firefox',
                // Chrome's UA contains "Safari" — order matters.
                str_contains($raw, 'Chrome/') => 'Chrome',
                str_contains($raw, 'Safari/') => 'Safari',
                default => null,
            },
            'platform' => match (true) {
                str_contains($raw, 'Windows') => 'Windows',
                str_contains($raw, 'Android') => 'Android',
                str_contains($raw, 'iPhone'), str_contains($raw, 'iPad') => 'iOS',
                str_contains($raw, 'Mac OS X') => 'macOS',
                str_contains($raw, 'Linux') => 'Linux',
                default => null,
            },
        ];
    }
}
