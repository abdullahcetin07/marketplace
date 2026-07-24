<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Models;

use App\Models\User;
use App\Shared\Traits\HasUuid;
use Database\Modules\Identity\Factories\UserDeviceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A recognised browser or app installation belonging to a user.
 *
 * WHY SEPARATE FROM UserSession: a user signs in from the same laptop fifty
 * times. That is one device and fifty sessions. Collapsing them would make
 * "trust this device" impossible to express and would show a security page
 * that is fifty identical rows of noise.
 *
 * `fingerprint` is a hash of stable request characteristics, NOT a tracking
 * identifier — it is derived per user, so the same browser produces different
 * fingerprints for different accounts and cannot be used to correlate them.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $fingerprint
 * @property string|null $platform
 * @property string|null $browser
 * @property string|null $device_type
 * @property string|null $location   coarse, geo-IP-populated; null until configured
 * @property bool $is_trusted
 * @property Carbon|null $trusted_at
 * @property Carbon $last_used_at
 * @property string|null $last_ip
 * @property-read User $user
 */
final class UserDevice extends Model
{
    /** @use HasFactory<UserDeviceFactory> */
    use HasFactory;

    use HasUuid;

    protected $table = 'user_devices';

    protected $fillable = [
        'user_id',
        'fingerprint',
        'platform',
        'browser',
        'device_type',
        'last_used_at',
        'last_ip',
        'location',
    ];

    /**
     * Derive a stable, per-user fingerprint from request characteristics.
     *
     * Deliberately coarse — user agent plus accepted languages. Including the
     * IP would invalidate the device on every network change, which defeats
     * the point; including anything finer would make it a tracking vector.
     *
     * Keyed with the user id and the app key so the value is meaningless
     * outside this application and cannot correlate one browser across
     * accounts.
     */
    public static function fingerprintFor(int $userId, ?string $userAgent, ?string $acceptLanguage): string
    {
        return hash_hmac(
            'sha256',
            implode('|', [$userId, $userAgent ?? '', $acceptLanguage ?? '']),
            (string) config('app.key'),
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<UserSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class, 'device_id');
    }

    /**
     * A trusted device may skip the 2FA challenge. Trust is time-limited: an
     * indefinitely trusted device is a permanent 2FA bypass on hardware the
     * user may no longer own.
     */
    public function isTrusted(): bool
    {
        if (! $this->is_trusted || $this->trusted_at === null) {
            return false;
        }

        $days = (int) config('marketplace.security.two_factor.trust_days', 30);

        return $this->trusted_at->gt(now()->subDays($days));
    }

    public function trust(): bool
    {
        return $this->forceFill([
            'is_trusted' => true,
            'trusted_at' => now(),
        ])->save();
    }

    public function revokeTrust(): bool
    {
        return $this->forceFill([
            'is_trusted' => false,
            'trusted_at' => null,
        ])->save();
    }

    /**
     * Human label for a security page: "Chrome on Windows".
     *
     * ALWAYS COMPUTED from browser + platform — the platform does not support
     * user-defined device names. A user identifies a device from what it is,
     * not from a name they had to invent.
     */
    public function label(): string
    {
        return trim(sprintf(
            '%s on %s',
            $this->browser ?? 'Unknown browser',
            $this->platform ?? 'unknown platform',
        ));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeTrusted(Builder $query): Builder
    {
        $days = (int) config('marketplace.security.two_factor.trust_days', 30);

        return $query->where('is_trusted', true)->where('trusted_at', '>', now()->subDays($days));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_trusted' => 'boolean',
            'trusted_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }
}
