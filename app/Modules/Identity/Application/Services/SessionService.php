<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Services;

use App\Models\User;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryContract;
use App\Modules\Identity\Domain\Contracts\SessionRepositoryContract;
use App\Modules\Identity\Domain\Events\SessionRevoked;
use App\Modules\Identity\Domain\Models\UserDevice;
use App\Modules\Identity\Domain\Models\UserSession;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Session and device tracking, and the revocation that actually ends access.
 *
 * THE IMPORTANT PROPERTY: `user_sessions` is a PROJECTION for the security
 * page, not the authentication mechanism. Marking a row revoked while the
 * cookie still works produces a UI that lies. Every method here that revokes
 * also destroys the underlying framework session or Sanctum token, in one
 * transaction.
 *
 * @see App\Modules\Identity\Domain\Models\UserSession
 * @see docs/authentication.md §Sessions
 */
final class SessionService
{
    /**
     * Persistence goes through the CONTRACTS (001_Architecture §4/§5), never a
     * direct query — so this service is unit-testable against fakes.
     */
    public function __construct(
        private readonly SessionRepositoryContract $sessions,
        private readonly DeviceRepositoryContract $devices,
    ) {}

    /**
     * Record a sign-in and return the session row.
     *
     * Resolves (or creates) the device first, so repeated sign-ins from one
     * browser collapse into a single device with many sessions rather than
     * fifty identical rows of noise.
     */
    public function start(User $user, Request $request, string $guard, ?int $tokenId = null): UserSession
    {
        $device = $this->resolveDevice($user, $request);

        return $this->sessions->create([
            'user_id' => $user->getKey(),
            'device_id' => $device->getKey(),
            // Exactly one of these: a cookie session or an API token.
            'session_id' => $tokenId === null && $request->hasSession()
                ? $request->session()->getId()
                : null,
            'token_id' => $tokenId,
            'guard' => $guard,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
            'location' => null, // populated by a geo-IP listener when configured
            'last_activity_at' => now(),
            'expires_at' => $this->expiryFor($tokenId),
        ]);
    }

    /**
     * Find or create the device this request comes from, and stamp its use.
     */
    public function resolveDevice(User $user, Request $request): UserDevice
    {
        $fingerprint = UserDevice::fingerprintFor(
            $user->getKey(),
            $request->userAgent(),
            $request->header('Accept-Language'),
        );

        $agent = $this->parseUserAgent($request->userAgent());

        return $this->devices->resolve($user, $fingerprint, [
            'platform' => $agent['platform'],
            'browser' => $agent['browser'],
            'device_type' => $agent['device_type'],
            'last_used_at' => now(),
            'last_ip' => $request->ip(),
        ]);
    }

    /**
     * Whether this is the first time we have seen this device for this user.
     *
     * Drives the "new sign-in" notification. Checked BEFORE start() creates
     * the row, or every login looks like a new device.
     */
    public function isNewDevice(User $user, Request $request): bool
    {
        return ! $this->devices->existsForFingerprint($user, UserDevice::fingerprintFor(
            $user->getKey(),
            $request->userAgent(),
            $request->header('Accept-Language'),
        ));
    }

    /**
     * A user's live sessions, newest activity first.
     *
     * @return Collection<int, UserSession>
     */
    public function activeFor(User $user, ?string $guard = null): Collection
    {
        return $this->sessions->activeFor($user, $guard);
    }

    /**
     * End one session — the row, the framework session, and the token.
     *
     * Returns false when the session does not belong to the user. The caller
     * has already been through the policy, but a service that trusts its
     * caller's scoping is one refactor away from an IDOR.
     */
    public function revoke(UserSession $session, User $actor, string $reason = 'manual'): bool
    {
        if ($session->user_id !== $actor->getKey() && ! $actor->isAdmin()) {
            return false;
        }

        DB::transaction(function () use ($session, $reason): void {
            $session->markRevoked($reason);
            $this->destroyUnderlying($session);
        });

        SessionRevoked::dispatch(
            $session->user_id,
            $session->user->uuid,
            [$session->uuid],
            $reason,
            $session->user_id === $actor->getKey() ? null : $actor->getKey(),
            $session->user->type->value,
        );

        return true;
    }

    /**
     * End every session except, optionally, the one making the request.
     *
     * `$exceptSessionId` is what makes "sign out everywhere else" usable — a
     * password change that also logs out the person changing it is hostile,
     * and they immediately sign back in, which defeats the point.
     */
    public function revokeAll(
        User $user,
        string $reason = 'all_devices',
        ?string $exceptSessionId = null,
        ?User $actor = null,
    ): int {
        $sessions = $this->sessions->activeExcept($user, $exceptSessionId);

        if ($sessions->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($sessions, $reason): void {
            foreach ($sessions as $session) {
                $session->markRevoked($reason);
                $this->destroyUnderlying($session);
            }
        });

        SessionRevoked::dispatch(
            $user->getKey(),
            $user->uuid,
            $sessions->pluck('uuid')->all(),
            $reason,
            $actor === null || $actor->is($user) ? null : $actor->getKey(),
            $user->type->value,
        );

        return $sessions->count();
    }

    /**
     * Keep the current session's activity stamp fresh. Called by middleware.
     */
    public function touch(Request $request, User $user): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $this->sessions
            ->findForFrameworkSession($user, $request->session()->getId(), $user->guardName())
            ?->touchActivity();
    }

    /**
     * Delete session rows idle beyond the retention window.
     *
     * Query-builder delete, not a model delete: this is housekeeping on
     * potentially millions of rows and does not need per-row events.
     */
    public function prune(?int $days = null): int
    {
        $days ??= (int) config('marketplace.security.sessions.prune_after_days', 30);

        return $this->sessions->pruneStale($days);
    }

    /**
     * Destroy whatever actually grants access for this session row.
     *
     * Without this the security page would show "revoked" while the cookie
     * kept working — the single worst failure mode in this module.
     */
    private function destroyUnderlying(UserSession $session): void
    {
        if ($session->session_id !== null) {
            // Delete straight from the session store. The framework offers no
            // API for destroying *another* user's session by id.
            DB::table(config('session.table', 'sessions'))
                ->where('id', $session->session_id)
                ->delete();
        }

        if ($session->token_id !== null) {
            DB::table('personal_access_tokens')
                ->where('id', $session->token_id)
                ->delete();
        }
    }

    /**
     * Cookie sessions expire on the configured lifetime; Sanctum tokens on
     * their own. Null means "no expiry beyond revocation".
     */
    private function expiryFor(?int $tokenId): ?\DateTimeInterface
    {
        if ($tokenId !== null) {
            $minutes = (int) config('sanctum.expiration', 0);

            return $minutes > 0 ? now()->addMinutes($minutes) : null;
        }

        return now()->addMinutes((int) config('session.lifetime', 120));
    }

    /**
     * Crude UA classification — for a human reading a security page, not for
     * analytics. A UA-parsing dependency needs constant updating to stay
     * accurate and buys nothing here.
     *
     * @return array{browser: string|null, platform: string|null, device_type: string|null}
     */
    private function parseUserAgent(?string $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return ['browser' => null, 'platform' => null, 'device_type' => null];
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
            'device_type' => match (true) {
                str_contains($raw, 'iPad'), str_contains($raw, 'Tablet') => 'tablet',
                str_contains($raw, 'Mobile'), str_contains($raw, 'iPhone'), str_contains($raw, 'Android') => 'mobile',
                default => 'desktop',
            },
        ];
    }
}
