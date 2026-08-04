<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Models;

use App\Models\User;
use App\Shared\Traits\HasUuid;
use Database\Modules\Identity\Factories\UserSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A signed-in session, surfaced to the user as "where you're logged in".
 *
 * WHY A TABLE WHEN LARAVEL ALREADY HAS `sessions`:
 * The framework's table is a serialised opaque payload keyed by session id. It
 * cannot answer "show me my active sessions and let me revoke the one in
 * Ankara" without deserialising every row, and it holds nothing for Sanctum
 * token sessions at all. This table is the user-facing projection: one row per
 * sign-in, with the device, location and revocation state a security page
 * needs.
 *
 * It is a projection, not the source of truth for authentication — revoking a
 * row here also deletes the framework session and the Sanctum token, which is
 * what actually ends access. @see SessionService
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int|null $device_id
 * @property string|null $session_id framework session id, when cookie-based
 * @property int|null $token_id personal_access_tokens.id, when API
 * @property string $guard
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $location
 * @property Carbon $last_activity_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 * @property Carbon|null $expires_at
 * @property-read User $user
 * @property-read UserDevice|null $device
 */
final class UserSession extends Model
{
    /** @use HasFactory<UserSessionFactory> */
    use HasFactory;

    use HasUuid;

    protected $table = 'user_sessions';

    protected $fillable = [
        'user_id',
        'device_id',
        'session_id',
        'token_id',
        'guard',
        'ip_address',
        'user_agent',
        'location',
        'last_activity_at',
        'expires_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<UserDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(UserDevice::class, 'device_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Whether this row represents the session making the current request —
     * so the UI can label it "this device" and refuse to offer a revoke button
     * that would log the user out mid-click.
     */
    public function isCurrent(): bool
    {
        if ($this->session_id !== null && session()->isStarted()) {
            return $this->session_id === session()->getId();
        }

        $token = current_actor()?->currentAccessToken();

        return $this->token_id !== null
            && $token !== null
            && $this->token_id === $token->getKey();
    }

    /**
     * Mark revoked. Does NOT itself end access — SessionService::revoke() also
     * destroys the framework session and deletes the token. Calling this alone
     * produces a row that claims to be revoked while the cookie still works.
     */
    public function markRevoked(string $reason): bool
    {
        return $this->forceFill([
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ])->save();
    }

    public function touchActivity(): void
    {
        // saveQuietly + a coarse threshold: this fires on every authenticated
        // request, and writing a row per request would make the session table
        // the busiest in the schema for no benefit.
        if ($this->last_activity_at->diffInMinutes(now()) < 1) {
            return;
        }

        $this->forceFill(['last_activity_at' => now()])->saveQuietly();
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn (Builder $q): Builder => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeStale(Builder $query, int $days = 30): Builder
    {
        return $query->where('last_activity_at', '<', now()->subDays($days));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',
            'revoked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
