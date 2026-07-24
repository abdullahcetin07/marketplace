<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * A member's role WITHIN one organization — a membership concept, entirely
 * separate from the platform's Spatie guard roles.
 *
 * Spatie `teams` is false, so these cannot be Spatie roles (which are global per
 * guard). A user is a Seller/SellerEmployee at the platform level AND holds an
 * OrganizationRole per organization they belong to (ADR-030).
 *
 * The role → capability map is the §5.1 matrix. Policies check the derived
 * CAPABILITY, never the role name (the platform rule holds inside the org too).
 * The Owner is implicit-all and exactly one per org (ADR-029).
 *
 * @see App\Modules\Organization\Domain\Enums\OrganizationCapability
 * @see docs/modules/Organization.md §5
 */
enum OrganizationRole: string
{
    use HasEnumHelpers;

    case Owner = 'owner';
    case Manager = 'manager';
    case Finance = 'finance';
    case Warehouse = 'warehouse';
    case Support = 'support';
    case Marketing = 'marketing';
    case Editor = 'editor';
    case Viewer = 'viewer';

    public function isOwner(): bool
    {
        return $this === self::Owner;
    }

    /**
     * Whether this role grants a capability. The Owner grants everything; every
     * other role is looked up in the matrix.
     */
    public function allows(OrganizationCapability $capability): bool
    {
        if ($this === self::Owner) {
            return true;
        }

        return in_array($capability, $this->capabilities(), true);
    }

    /**
     * The capabilities this role grants (§5.1). Owner returns the full set.
     *
     * @return array<int, OrganizationCapability>
     */
    public function capabilities(): array
    {
        if ($this === self::Owner) {
            return OrganizationCapability::cases();
        }

        return match ($this) {
            self::Manager => [
                OrganizationCapability::OrganizationView,
                OrganizationCapability::OrganizationUpdate,
                OrganizationCapability::OrganizationManageKyc,
                OrganizationCapability::MemberView,
                OrganizationCapability::MemberInvite,
                OrganizationCapability::MemberUpdateRole,
                OrganizationCapability::MemberRemove,
                OrganizationCapability::InvitationManage,
                OrganizationCapability::BankAccountView,
                OrganizationCapability::DocumentUpload,
                OrganizationCapability::StoreRequestCreate,
                OrganizationCapability::StoreRequestCancel,
                // Managers run the storefront's operations (ADR-033 §9.1).
                OrganizationCapability::StoreManage,
                OrganizationCapability::SettingsUpdate,
            ],
            self::Finance => [
                OrganizationCapability::OrganizationView,
                OrganizationCapability::MemberView,
                OrganizationCapability::BankAccountView,
                OrganizationCapability::BankAccountUpdate,
                OrganizationCapability::DocumentUpload,
            ],
            self::Editor => [
                OrganizationCapability::OrganizationView,
                OrganizationCapability::MemberView,
                OrganizationCapability::DocumentUpload,
            ],
            // Warehouse, Support, Marketing, Viewer — read-only presence.
            default => [
                OrganizationCapability::OrganizationView,
                OrganizationCapability::MemberView,
            ],
        };
    }

    /**
     * Roles that may be ASSIGNED to a member. The Owner is excluded — ownership
     * is reached only by transfer (ADR-029), never by assigning the role.
     *
     * @return array<int, self>
     */
    public static function assignable(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $role): bool => $role !== self::Owner,
        ));
    }
}
