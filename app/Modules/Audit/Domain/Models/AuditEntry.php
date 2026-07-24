<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Models;

use App\Modules\Audit\Domain\Enums\AuditEventType;
use App\Modules\Audit\Domain\Enums\AuditSeverity;
use App\Shared\Traits\HasUuid;
use Database\Modules\Audit\Factories\AuditEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An immutable forensic event: what happened, of what kind, how serious, to what
 * record if any, by whom, from where.
 *
 * THE PLATFORM'S FORENSIC EVENT STORE (ADR-027). Model changes are one category
 * (`event_type` = model_*); a detected attack, a permission grant, a store
 * transfer are others. A security event may name no record at all — an attack on
 * an address with no account — so `auditable` and the model verb `event` are
 * nullable, while `event_type` and `severity` are always present.
 *
 * AUDIT vs ACTIVITY — deliberately separate tables:
 *   Audit    is the immutable forensic log — row-centric, evidential, 730 days.
 *   Activity is the user's own timeline    — actor-centric, narrative, 365 days.
 * Merging them produces a table that answers neither question without a filter,
 * and forces one retention policy onto two kinds of evidence.
 *
 * APPEND-ONLY. There is no update path and no `updated_at`. An audit trail that
 * can be edited is not an audit trail — the model blocks updates and deletes
 * outright.
 *
 * @property int $id
 * @property string $uuid
 * @property AuditEventType $event_type
 * @property AuditSeverity $severity
 * @property string|null $event            model verb: created|updated|deleted|restored
 * @property string|null $auditable_type
 * @property int|null $auditable_id
 * @property string|null $causer_type
 * @property int|null $causer_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property array<string, mixed>|null $metadata
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $browser
 * @property string|null $platform
 * @property string|null $url
 * @property string|null $correlation_id
 *
 * @see App\Modules\Audit\Domain\Concerns\Auditable
 * @see App\Modules\Audit\Domain\Enums\AuditEventType
 * @see docs/audit.md
 */
final class AuditEntry extends Model
{
    /** @use HasFactory<AuditEntryFactory> */
    use HasFactory;

    use HasUuid;

    public const string EVENT_CREATED = 'created';
    public const string EVENT_UPDATED = 'updated';
    public const string EVENT_DELETED = 'deleted';
    public const string EVENT_RESTORED = 'restored';

    /** Append-only: nothing ever updates a row. */
    public const UPDATED_AT = null;

    protected $table = 'audit_entries';

    protected $fillable = [
        'event_type',
        'severity',
        'event',
        'auditable_type',
        'auditable_id',
        'causer_type',
        'causer_id',
        'old_values',
        'new_values',
        'metadata',
        'ip_address',
        'user_agent',
        'browser',
        'platform',
        'url',
        'correlation_id',
    ];

    /**
     * The record that changed.
     *
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Who caused it. Null for system writes — a seeder, a queue worker, a
     * console command. Attributing those to a person would make the trail lie.
     *
     * @return MorphTo<Model, $this>
     */
    public function causer(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Attribute names that changed in this entry.
     *
     * @return array<int, string>
     */
    public function changedAttributes(): array
    {
        return array_keys($this->new_values ?? []);
    }

    /**
     * Before/after pairs, ready to render as a diff table.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function diff(): array
    {
        $diff = [];

        foreach ($this->new_values ?? [] as $attribute => $new) {
            $diff[$attribute] = [
                'old' => $this->old_values[$attribute] ?? null,
                'new' => $new,
            ];
        }

        return $diff;
    }

    public function wasChanged(string $attribute): bool
    {
        return array_key_exists($attribute, $this->new_values ?? []);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForModel(Builder $query, Model $model): Builder
    {
        return $query->where('auditable_type', $model::class)
            ->where('auditable_id', $model->getKey());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCausedBy(Builder $query, Model $causer): Builder
    {
        return $query->where('causer_type', $causer::class)
            ->where('causer_id', $causer->getKey());
    }

    /**
     * Everything that happened under one request or job run.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForCorrelation(Builder $query, string $correlationId): Builder
    {
        return $query->where('correlation_id', $correlationId);
    }

    /**
     * The security trail — the SIEM feed's subscription. Every event_type whose
     * value is prefixed `security_`.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSecurity(Builder $query): Builder
    {
        return $query->where('event_type', 'like', 'security_%');
    }

    /**
     * Events at or above a severity floor — "everything Warning or worse".
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAtLeastSeverity(Builder $query, AuditSeverity $floor): Builder
    {
        $atLeast = array_map(
            static fn (AuditSeverity $s): string => $s->value,
            array_filter(AuditSeverity::cases(), static fn (AuditSeverity $s): bool => $s->atLeast($floor)),
        );

        return $query->whereIn('severity', $atLeast);
    }

    /**
     * @return bool
     */
    public function isSecurityEvent(): bool
    {
        return $this->event_type->isSecurity();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => AuditEventType::class,
            'severity' => AuditSeverity::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        /*
        | Immutability, enforced rather than documented. Both hooks return
        | false, which cancels the operation. Retention pruning bypasses the
        | model entirely with a query builder delete — see the scheduled
        | command — so this does not prevent legitimate housekeeping.
        */
        static::updating(static fn (): bool => false);
        static::deleting(static fn (): bool => false);
    }
}
