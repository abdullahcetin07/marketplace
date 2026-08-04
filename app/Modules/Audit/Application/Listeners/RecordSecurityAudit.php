<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application\Listeners;

use App\Core\Domain\Context\AuditContext;
use App\Modules\Audit\Application\Services\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditEventType;
use App\Modules\Audit\Domain\Enums\AuditSeverity;
use App\Modules\Identity\Domain\Events\PasswordChanged;
use App\Modules\Identity\Domain\Events\PasswordResetRequested;
use App\Modules\Identity\Domain\Events\SessionRevoked;
use App\Modules\Identity\Domain\Events\SuspiciousLoginDetected;
use App\Modules\Identity\Domain\Events\TwoFactorDisabled;
use App\Modules\Identity\Domain\Events\TwoFactorEnabled;
use App\Shared\Enums\LoginThreatKind;
use App\Shared\Enums\UserType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;

/**
 * Turns security signals from across the platform into forensic audit entries.
 *
 * THE MODULE BOUNDARY, consumer side (ADR-027). Identity announces
 * SuspiciousLoginDetected and stops; Audit subscribes and grades it. Identity
 * does not know the forensic store exists — that is what the layering test
 * asserts, and it is why Audit may import Identity's events but never the
 * reverse.
 *
 * Identity classifies the SHAPE of the attack (its LoginThreatKind); Audit
 * decides how heavily the trail weighs it. Keeping that judgement here, not on
 * the event, is what lets the two modules disagree about severity without a
 * code change on the producer.
 *
 * NOT QUEUED. A forensic record of an attack that is unfolding now must exist
 * now, not whenever a worker next drains the queue.
 *
 * @see App\Modules\Audit\Application\Services\AuditLogger
 * @see docs/audit.md
 */
final class RecordSecurityAudit
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            SuspiciousLoginDetected::class => 'onSuspiciousLogin',
            // 2FA state changes: excluded from the model-diff trail (they are
            // secrets), so the forensic record is written here from the event.
            TwoFactorEnabled::class => 'onTwoFactorEnabled',
            TwoFactorDisabled::class => 'onTwoFactorDisabled',
            // The password-reset lifecycle. `password` is secret-excluded too,
            // so these are the only forensic record of the chain.
            PasswordResetRequested::class => 'onPasswordResetIssued',
            PasswordChanged::class => 'onPasswordChanged',
            SessionRevoked::class => 'onSessionsRevoked',
        ];
    }

    public function onSuspiciousLogin(SuspiciousLoginDetected $event): void
    {
        [$type, $severity] = match ($event->kind) {
            // Concentrated grinding of one account. High — a real, targeted
            // attempt, but bounded to one address.
            LoginThreatKind::BruteForce => [AuditEventType::SecurityBruteForce, AuditSeverity::High],
            // A botnet replaying a breached list. Critical — it implies the
            // credentials are already out, and it does not stop at one account.
            LoginThreatKind::CredentialStuffing => [AuditEventType::SecurityCredentialStuffing, AuditSeverity::Critical],
        };

        $this->audit->record(
            type: $type,
            severity: $severity,
            auditable: $this->targetedUser($event),
            metadata: [
                'email' => $event->email,
                'guard' => $event->guard,
                'ip' => $event->ipAddress,
                'failure_count' => $event->failureCount,
                'distinct_ips' => $event->distinctIps,
            ],
        );
    }

    /**
     * A user finished enrolling in 2FA. Notice-level — a positive security
     * change, recorded because the diff trail cannot see it.
     */
    public function onTwoFactorEnabled(TwoFactorEnabled $event): void
    {
        $this->audit->record(
            type: AuditEventType::SecurityTwoFactorEnabled,
            severity: AuditSeverity::Notice,
            auditable: $this->userByGuard($event->userId, $event->guard),
            metadata: $this->reasonMetadata(),
        );
    }

    /**
     * 2FA was switched off. High when an administrator did it — that is the
     * helpdesk-access takeover path — and Warning when the owner did it
     * themselves. The reason the actor gave rides the audit context.
     */
    public function onTwoFactorDisabled(TwoFactorDisabled $event): void
    {
        $this->audit->record(
            type: AuditEventType::SecurityTwoFactorDisabled,
            severity: $event->byAdministrator ? AuditSeverity::High : AuditSeverity::Warning,
            auditable: $this->userByGuard($event->userId, $event->guard),
            metadata: $this->reasonMetadata(['by_administrator' => $event->byAdministrator]),
        );
    }

    /**
     * A reset link was issued (self-service "forgot password" or an admin
     * triggering one). The first step of the forensic reset timeline. Notice —
     * routine, but the lever an account-recovery attack pulls. Records actor
     * (causer), target, reason, IP, user-agent, correlation id and time.
     */
    public function onPasswordResetIssued(PasswordResetRequested $event): void
    {
        $this->audit->record(
            type: AuditEventType::SecurityPasswordResetIssued,
            severity: AuditSeverity::Notice,
            auditable: $this->userByGuard($event->userId, $event->guard),
            metadata: $this->reasonMetadata(['ip' => $event->ipAddress]),
        );
    }

    /**
     * A password actually changed — the second half of the reset timeline, or a
     * deliberate self-service change. `password` is secret-excluded from the
     * diff trail, so this event is its only forensic record.
     */
    public function onPasswordChanged(PasswordChanged $event): void
    {
        $this->audit->record(
            type: $event->viaReset
                ? AuditEventType::SecurityPasswordResetCompleted
                : AuditEventType::SecurityPasswordChanged,
            severity: AuditSeverity::Notice,
            auditable: $this->userByGuard($event->userId, $event->guard),
            metadata: $this->reasonMetadata(['via_reset' => $event->viaReset]),
        );
    }

    /**
     * Sessions were revoked — the tail of the reset timeline, and also
     * "sign out everywhere" or an admin terminating a session. Info: a normal
     * consequence, recorded so the chain is complete.
     */
    public function onSessionsRevoked(SessionRevoked $event): void
    {
        $this->audit->record(
            type: AuditEventType::SecuritySessionsRevoked,
            severity: AuditSeverity::Info,
            auditable: $this->userByGuard($event->userId, $event->guard),
            metadata: $this->reasonMetadata([
                'reason_code' => $event->reason,
                'count' => $event->count(),
                'by_administrator' => $event->revokedByUserId !== null,
            ]),
        );
    }

    /**
     * The account under attack, when the address is a real one, resolved through
     * its type so the morph names the concrete subclass (Seller, not the base
     * User) and the entry shows up on that user's own audit history. Null when
     * the attack hit an address that was never registered.
     */
    private function targetedUser(SuspiciousLoginDetected $event): ?Model
    {
        return $this->userByGuard($event->userId, $event->guard);
    }

    /**
     * Resolve a user through its actor type so the morph names the concrete
     * subclass, not the base User. Null when the id or guard is missing.
     */
    private function userByGuard(?int $userId, ?string $guard): ?Model
    {
        if ($userId === null || $guard === null) {
            return null;
        }

        $model = UserType::tryFrom($guard)?->model();

        return $model === null ? null : $model::query()->find($userId);
    }

    /**
     * Fold the actor-supplied reason (from the audit context) into the entry's
     * metadata, alongside any extra keys. Reason is present only when an admin
     * action set it via AuditContext::withReasonFor().
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function reasonMetadata(array $extra = []): array
    {
        $reason = AuditContext::current()->reason;

        return $reason === null ? $extra : ['reason' => $reason, ...$extra];
    }
}
