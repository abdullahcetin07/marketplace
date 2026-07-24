<?php

declare(strict_types=1);

namespace App\Modules\Activity\Domain\Models;

use App\Models\User;
use App\Modules\Activity\Domain\Enums\ActivityType;
use App\Shared\Traits\HasUuid;
use Database\Modules\Activity\Factories\ActivityEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * What a user did — the actor-centric counterpart to AuditEntry.
 *
 * AUDIT vs ACTIVITY:
 *   Audit    "the price on offer #42 went from 100 to 90 at 14:32"
 *   Activity "Ayşe signed in from a new device at 14:30"
 *
 * They overlap in neither shape nor retention. An activity entry is a sentence
 * a human reads on a security page; an audit entry is a diff a lawyer reads
 * during a dispute. One table would serve both badly.
 *
 * Append-only, like AuditEntry.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $user_id
 * @property ActivityType $type
 * @property string|null $description
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $properties
 * @property string|null $ip_address
 * @property string|null $browser
 * @property string|null $platform
 * @property string|null $correlation_id
 * @property-read User|null $user
 *
 * @see docs/audit.md
 */
final class ActivityEntry extends Model
{
    /** @use HasFactory<ActivityEntryFactory> */
    use HasFactory;

    use HasUuid;

    /** Append-only. */
    public const UPDATED_AT = null;

    protected $table = 'activity_entries';

    protected $fillable = [
        'user_id',
        'type',
        'description',
        'subject_type',
        'subject_id',
        'properties',
        'ip_address',
        'browser',
        'platform',
        'correlation_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Optional target — the session that was revoked, the device trusted.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Human sentence for a security page, translated.
     *
     * Falls back to the stored description, then to a generic translation of
     * the type — an entry always renders as something readable.
     */
    public function label(): string
    {
        if (filled($this->description)) {
            return (string) $this->description;
        }

        $key = 'activity.'.$this->type->value;
        $translated = __($key, $this->properties ?? []);

        return is_string($translated) && $translated !== $key
            ? $translated
            : $this->type->label();
    }

    public function property(string $key, mixed $default = null): mixed
    {
        return data_get($this->properties, $key, $default);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, ActivityType ...$types): Builder
    {
        return $query->whereIn('type', array_column($types, 'value'));
    }

    /**
     * What the user themselves may see on their own account page — a strict
     * subset. Internal entries (permission changes made by an admin) are
     * excluded from this scope, not merely hidden by the view.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUserVisible(Builder $query): Builder
    {
        return $query->whereIn('type', array_column(ActivityType::userVisible(), 'value'));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSecuritySensitive(Builder $query): Builder
    {
        return $query->whereIn('type', array_column(ActivityType::securitySensitive(), 'value'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'properties' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Append-only, same reasoning as AuditEntry. Retention pruning uses
        // the query builder and bypasses this.
        static::updating(static fn (): bool => false);
        static::deleting(static fn (): bool => false);
    }
}
