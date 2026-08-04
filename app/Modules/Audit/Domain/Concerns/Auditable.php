<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Concerns;

use App\Core\Domain\Context\AuditContext;
use App\Modules\Audit\Domain\Enums\AuditEventType;
use App\Modules\Audit\Domain\Enums\AuditSeverity;
use App\Modules\Audit\Domain\Models\AuditEntry;
use Closure;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Records every change to a model in the audit trail.
 *
 *     final class Setting extends Model
 *     {
 *         use \App\Modules\Audit\Domain\Concerns\Auditable;
 *
 *         protected array $auditExclude = ['cached_value'];
 *     }
 *
 * WHAT IS RECORDED: only attributes that actually changed, so an idempotent
 * save writes nothing. Excluded attributes never appear in either payload.
 *
 * WHAT IS NEVER RECORDED: `password`, `remember_token` and the two-factor
 * columns are excluded globally and cannot be re-enabled per model. An audit
 * trail containing password hashes is a credential store with a long retention
 * policy and a permissive read scope.
 *
 * REQUEST FACTS ARRIVE AS EXPLICIT CONTEXT (ADR-019). This trait does not call
 * `request()` — Presentation pushes an AuditContext in, and outside HTTP the
 * context says so rather than yielding nulls that look like missing data.
 *
 * @see App\Core\Domain\Context\AuditContext
 * @see App\Modules\Audit\Domain\Models\AuditEntry
 * @see docs/audit.md
 */
trait Auditable
{
    /**
     * Attributes never written to the audit trail, for any model.
     *
     * @var array<int, string>
     */
    private static array $globallyExcluded = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'created_at',
        'updated_at',
    ];

    /**
     * Suppression flag for bulk operations.
     */
    private static bool $auditingDisabled = false;

    public static function bootAuditable(): void
    {
        static::created(static fn (self $model) => $model->recordAudit(AuditEntry::EVENT_CREATED));
        static::updated(static fn (self $model) => $model->recordAudit(AuditEntry::EVENT_UPDATED));
        static::deleted(static fn (self $model) => $model->recordAudit(AuditEntry::EVENT_DELETED));

        if (method_exists(static::class, 'restored')) {
            static::restored(static fn (self $model) => $model->recordAudit(AuditEntry::EVENT_RESTORED));
        }
    }

    /**
     * Run a callback without writing audit entries.
     *
     * For imports and back-fills where a row-per-record trail would be noise,
     * not evidence. Use sparingly — the point of the trail is that it is
     * complete.
     *
     * @template TValue
     *
     * @param Closure(): TValue $callback
     *
     * @return TValue
     */
    public static function withoutAuditing(Closure $callback): mixed
    {
        $previous = self::$auditingDisabled;
        self::$auditingDisabled = true;

        try {
            return $callback();
        } finally {
            self::$auditingDisabled = $previous;
        }
    }

    /**
     * @return MorphMany<AuditEntry, $this>
     */
    public function audits(): MorphMany
    {
        return $this->morphMany(AuditEntry::class, 'auditable')->latest('id');
    }

    /**
     * Attributes excluded for this model, on top of the global list. Override
     * per model.
     *
     * @return array<int, string>
     */
    public function auditExclude(): array
    {
        return property_exists($this, 'auditExclude') ? $this->auditExclude : [];
    }

    /**
     * Write one entry. Returns null when there was nothing to record.
     */
    public function recordAudit(string $event): ?AuditEntry
    {
        if (self::$auditingDisabled) {
            return null;
        }

        [$old, $new] = $this->auditPayload($event);

        // An update that changed only excluded attributes is not a change
        // worth a row.
        if ($event === AuditEntry::EVENT_UPDATED && $new === []) {
            return null;
        }

        $causer = current_actor();

        /*
        | ADR-019: request facts arrive as an explicit context pushed in from
        | Presentation. The Domain layer no longer calls request().
        |
        | Outside HTTP this yields a system context with null request fields
        | and origin='console'|'queue' — which is honest, where the old
        | request() call silently produced nulls that looked like missing data.
        */
        $context = AuditContext::current();

        return AuditEntry::query()->create([
            // The fine-grained model verb stays; `event_type` is the generic
            // category the forensic store queries on (ADR-027). A model change
            // is Info by default — routine, not something to page anyone about.
            'event_type' => AuditEventType::forModelEvent($event),
            'severity' => AuditSeverity::Info,
            'event' => $event,
            'auditable_type' => static::class,
            'auditable_id' => $this->getKey(),
            'causer_type' => $causer === null ? null : $causer::class,
            'causer_id' => $causer?->getKey(),
            'old_values' => $old === [] ? null : $old,
            'new_values' => $new === [] ? null : $new,
            // WHY, when the actor supplied a reason (an admin action). Self-
            // service edits carry none, so metadata stays null. Kept out of
            // old/new_values because it is context, not a changed field.
            'metadata' => $context->reason === null ? null : ['reason' => $context->reason],
            'ip_address' => $context->ipAddress,
            'user_agent' => $context->userAgent,
            'browser' => $context->browser,
            'platform' => $context->platform,
            'url' => $context->url ?? $context->origin,
            'correlation_id' => $context->correlationId,
        ]);
    }

    /**
     * Build the before/after payloads for an event.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function auditPayload(string $event): array
    {
        $excluded = [...self::$globallyExcluded, ...$this->auditExclude()];

        $strip = static fn (array $attributes): array => array_diff_key(
            $attributes,
            array_flip($excluded),
        );

        return match ($event) {
            // On create there is no "before"; the whole record is the "after".
            AuditEntry::EVENT_CREATED => [[], $strip($this->getAttributes())],
            // On delete the record IS the "before"; there is no "after".
            AuditEntry::EVENT_DELETED => [$strip($this->getAttributes()), []],
            default => [$strip($this->getOriginal()), $strip($this->getChanges())],
        };
    }
}
