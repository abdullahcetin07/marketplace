<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Models;

use App\Models\User;
use App\Shared\Traits\HasUuid;
use Database\Modules\Identity\Factories\LoginAttemptFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Login history — every attempt, successful or not.
 *
 * FAILURES ARE THE POINT. A table of successful logins tells you almost
 * nothing; a run of forty failures against one address from twelve IPs is
 * credential stuffing, and it is the earliest signal available. Both are kept.
 *
 * `user_id` is nullable because an attempt against a non-existent address must
 * still be recorded — that is exactly what enumeration looks like.
 *
 * NEVER stores the attempted password, not even hashed.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $user_id
 * @property string $email as typed, so enumeration attempts are visible
 * @property string $guard
 * @property bool $successful
 * @property string|null $failure_reason
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $platform
 * @property string|null $browser
 * @property string|null $location
 * @property-read User|null $user
 */
final class LoginAttempt extends Model
{
    /** @use HasFactory<LoginAttemptFactory> */
    use HasFactory;

    use HasUuid;

    /**
     * Login history is append-only. Nothing updates a row once written, so the
     * `updated_at` column would be dead weight on the busiest audit table.
     */
    public const UPDATED_AT = null;

    protected $table = 'login_attempts';

    protected $fillable = [
        'user_id',
        'email',
        'guard',
        'successful',
        'failure_reason',
        'ip_address',
        'user_agent',
        'platform',
        'browser',
        'location',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Detection queries live in the repository, not here
    |--------------------------------------------------------------------------
    |
    | `recentFailuresFor()` and `distinctIpsFor()` moved to
    | LoginAttemptRepositoryContract. Under ADR-011 they were arguably
    | lightweight helpers, but they are aggregate queries ACROSS rows rather
    | than facts about one row — which makes them repository work.
    |
    |   app(LoginAttemptRepositoryContract::class)->recentFailuresFor(...);
    */

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('successful', true);
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('successful', false);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'successful' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Normalise on write so "User@Example.com" and "user@example.com"
        // aggregate into the same failure count rather than dodging detection.
        self::creating(static function (self $attempt): void {
            $attempt->email = mb_strtolower(trim($attempt->email));
        });
    }
}
