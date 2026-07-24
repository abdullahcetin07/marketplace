<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * A capability a member may hold WITHIN an organization (§5.1 matrix).
 *
 * These are org-scoped abilities resolved from the member's role, NOT platform
 * Spatie permissions (Spatie `teams` is false, so its roles cannot be
 * org-scoped). The member policies check these; the Owner holds all of them.
 *
 * An enum, not a table: adding a capability means writing the code that checks
 * it, and mapping it into the role matrix — both code changes by definition.
 *
 * @see App\Modules\Organization\Domain\Enums\OrganizationRole
 * @see docs/modules/Organization.md §5.1
 */
enum OrganizationCapability: string
{
    use HasEnumHelpers;

    case OrganizationView = 'organization.view';
    case OrganizationUpdate = 'organization.update';
    case OrganizationManageKyc = 'organization.manage_kyc';

    case MemberView = 'member.view';
    case MemberInvite = 'member.invite';
    case MemberUpdateRole = 'member.update_role';
    case MemberRemove = 'member.remove';

    case InvitationManage = 'invitation.manage';

    case BankAccountView = 'bank_account.view';
    case BankAccountUpdate = 'bank_account.update';

    case DocumentUpload = 'document.upload';

    case StoreRequestCreate = 'store_request.create';
    case StoreRequestCancel = 'store_request.cancel';

    // Store-module capability (added for Store; ADR-033 §9.1). Answered for
    // Store through the Core OrganizationAuthorizationContract — Organization
    // stays the single source of truth. Covers general storefront operations,
    // delegable to a Manager. (A domain-management capability is intentionally
    // absent: v1 stores are path-addressed with no per-store domain, ADR-035;
    // custom domains + their Owner-only capability arrive with a future ADR.)
    case StoreManage = 'store.manage';

    case SettingsUpdate = 'settings.update';

    case OwnershipTransfer = 'ownership.transfer';
}
