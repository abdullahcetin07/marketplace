<?php

declare(strict_types=1);

namespace App\Modules\Activity\Application\Services;

use App\Models\User;
use App\Modules\Activity\Domain\Enums\ActivityType;
use App\Modules\Activity\Domain\Models\ActivityEntry;
use Illuminate\Database\Eloquent\Model;

/**
 * The one way activity gets recorded.
 *
 * A service rather than direct `ActivityEntry::create()` calls scattered around
 * because every entry needs the same request context — IP, browser, platform,
 * correlation id — and gathering it at each call site guarantees some call
 * sites forget.
 *
 *     app(ActivityLogger::class)->log(ActivityType::PasswordChanged, $user);
 *
 * @see App\Modules\Activity\Domain\Models\ActivityEntry
 * @see docs/audit.md
 */
final class ActivityLogger
{
    /**
     * Record an activity.
     *
     * `$user` defaults to the current actor. Pass it explicitly for actions
     * taken ON a user BY someone else — an admin changing a seller's role is
     * activity on the seller's timeline, not the admin's.
     *
     * @param  array<string, mixed>  $properties
     */
    public function log(
        ActivityType $type,
        User|int|null $user = null,
        ?Model $subject = null,
        ?string $description = null,
        array $properties = [],
    ): ActivityEntry {
        $userId = match (true) {
            $user instanceof User => $user->getKey(),
            is_int($user) => $user,
            default => current_actor()?->getKey(),
        };

        $agent = $this->parseUserAgent();

        return ActivityEntry::query()->create([
            'user_id' => $userId,
            'type' => $type,
            'description' => $description,
            'subject_type' => $subject === null ? null : $subject::class,
            'subject_id' => $subject?->getKey(),
            'properties' => $properties === [] ? null : $properties,
            'ip_address' => request()->ip(),
            'browser' => $agent['browser'],
            'platform' => $agent['platform'],
            'correlation_id' => correlation_id() ?: null,
        ]);
    }

    /**
     * Record an activity for an email that may not correspond to an account —
     * a failed login against an address that does not exist.
     *
     * @param  array<string, mixed>  $properties
     */
    public function logAnonymous(ActivityType $type, string $email, array $properties = []): ActivityEntry
    {
        return $this->log(
            type: $type,
            user: null,
            properties: [...$properties, 'email' => mb_strtolower($email)],
        );
    }

    /**
     * Crude UA classification. Deliberately not a parsing library: this is for
     * a human reading a security page, and a UA parser is a dependency that
     * needs constant updating to stay accurate.
     *
     * @return array{browser: string|null, platform: string|null}
     */
    private function parseUserAgent(): array
    {
        $raw = request()->userAgent();

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
