<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application\Services;

use App\Core\Domain\Context\AuditContext;
use App\Modules\Audit\Domain\Enums\AuditEventType;
use App\Modules\Audit\Domain\Enums\AuditSeverity;
use App\Modules\Audit\Domain\Models\AuditEntry;
use Illuminate\Database\Eloquent\Model;

/**
 * The one way a NON-model forensic event gets recorded.
 *
 * Model changes write themselves through the Auditable trait. Everything else —
 * a detected attack, a permission grant, a store transfer — has no Eloquent
 * event to hang off, so it comes through here. A service rather than scattered
 * `AuditEntry::create()` calls for the same reason ActivityLogger is one: every
 * row needs the same request context (IP, agent, correlation id), and gathering
 * it at each call site guarantees some call site forgets.
 *
 * REQUEST FACTS ARRIVE AS EXPLICIT CONTEXT (ADR-019), exactly as the trait
 * does — this reads AuditContext, never `request()`, so it is correct inside a
 * queue worker or console command too.
 *
 * @see App\Modules\Audit\Domain\Concerns\Auditable
 * @see App\Core\Domain\Context\AuditContext
 * @see docs/audit.md
 */
final class AuditLogger
{
    /**
     * Record a forensic event that is not a model diff.
     *
     * `$auditable` is the record the event is ABOUT when there is one — the
     * targeted user for a brute-force attempt — and null when there is not, as
     * when the attacked address has no account. `$causer` defaults to the
     * current actor, which is correctly null for an unauthenticated attacker.
     *
     * @param  array<string, mixed>  $metadata  event context with no model diff
     */
    public function record(
        AuditEventType $type,
        AuditSeverity $severity,
        ?Model $auditable = null,
        array $metadata = [],
        ?Model $causer = null,
    ): AuditEntry {
        $causer ??= current_actor();
        $context = AuditContext::current();

        return AuditEntry::query()->create([
            'event_type' => $type,
            'severity' => $severity,
            // No model verb: this is not a create/update/delete.
            'event' => null,
            'auditable_type' => $auditable === null ? null : $auditable::class,
            'auditable_id' => $auditable?->getKey(),
            'causer_type' => $causer === null ? null : $causer::class,
            'causer_id' => $causer?->getKey(),
            'old_values' => null,
            'new_values' => null,
            'metadata' => $metadata === [] ? null : $metadata,
            'ip_address' => $context->ipAddress,
            'user_agent' => $context->userAgent,
            'browser' => $context->browser,
            'platform' => $context->platform,
            'url' => $context->url ?? $context->origin,
            'correlation_id' => $context->correlationId,
        ]);
    }

    /**
     * Convenience for the common case: a security event, defaulting to High.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function security(
        AuditEventType $type,
        ?Model $auditable = null,
        array $metadata = [],
        AuditSeverity $severity = AuditSeverity::High,
    ): AuditEntry {
        return $this->record($type, $severity, $auditable, $metadata);
    }
}
