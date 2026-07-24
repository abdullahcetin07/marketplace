<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * The CATEGORY of a forensic event.
 *
 * Model changes are one category among many (ADR-027). Before that ruling the
 * table only knew `created|updated|deleted|restored`; treating those as the
 * whole vocabulary is what made recording a brute-force login impossible without
 * inventing a fake model diff.
 *
 * An enum, not a table (CLAUDE.md "enum or lookup table?"): a new category is
 * meaningless until code exists to emit and interpret it, so adding a case is a
 * code change by definition.
 *
 * SECURITY_* events are written by Audit's own listeners in reaction to domain
 * events from other modules — Audit never imports them, they announce and it
 * subscribes. The MODEL_* events are written by the Auditable trait. The rest
 * (PERMISSION_CHANGED, COMMISSION_CHANGED, …) are seams for later sprints,
 * declared now so the event vocabulary is stable before code accretes around it.
 *
 * @see App\Modules\Audit\Domain\Enums\AuditSeverity
 * @see App\Modules\Audit\Domain\Concerns\Auditable
 * @see docs/audit.md
 */
enum AuditEventType: string
{
    use HasEnumHelpers;

    // Model lifecycle — written by the Auditable trait.
    case ModelCreated = 'model_created';
    case ModelUpdated = 'model_updated';
    case ModelDeleted = 'model_deleted';
    case ModelRestored = 'model_restored';

    // Security — written by Audit listeners reacting to Identity's events.
    case SecurityLogin = 'security_login';
    case SecurityBruteForce = 'security_brute_force';
    case SecurityCredentialStuffing = 'security_credential_stuffing';
    // Two-factor state changes: the columns are secrets and excluded from the
    // diff trail, so the forensic record comes through the event instead.
    case SecurityTwoFactorEnabled = 'security_two_factor_enabled';
    case SecurityTwoFactorDisabled = 'security_two_factor_disabled';
    // The password-reset lifecycle. `password` is secret-excluded, so — like
    // 2FA — the forensic record of a change comes through the event.
    case SecurityPasswordResetIssued = 'security_password_reset_issued';
    case SecurityPasswordResetCompleted = 'security_password_reset_completed';
    case SecurityPasswordChanged = 'security_password_changed';
    case SecuritySessionsRevoked = 'security_sessions_revoked';

    // Governance seams for later sprints. No emitter yet — declared so the
    // vocabulary does not churn once the modules that raise them arrive.
    case PermissionChanged = 'permission_changed';
    case CommissionChanged = 'commission_changed';
    case PaymentConfigurationChanged = 'payment_configuration_changed';
    case StoreTransferred = 'store_transferred';

    /**
     * The model verb this category corresponds to, or null for events that are
     * not a model lifecycle change. Lets the Auditable trait keep writing the
     * fine-grained `event` column while also classifying the row.
     */
    public static function forModelEvent(string $event): self
    {
        return match ($event) {
            'created' => self::ModelCreated,
            'updated' => self::ModelUpdated,
            'deleted' => self::ModelDeleted,
            'restored' => self::ModelRestored,
            default => self::ModelUpdated,
        };
    }

    /**
     * Whether this is a security-domain event — the filter behind "show me the
     * security trail", and the set a SIEM feed subscribes to.
     */
    public function isSecurity(): bool
    {
        return str_starts_with($this->value, 'security_');
    }
}
