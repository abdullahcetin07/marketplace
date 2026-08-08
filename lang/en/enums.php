<?php

declare(strict_types=1);

/*
| Enum labels, resolved by App\Shared\Enums\Concerns\HasEnumHelpers::label().
| Keyed by the enum's short class name, then by case value.
|
| Country, Currency and Language are NO LONGER HERE — they became lookup
| tables in Sprint 1, and their display names live in the `name` /
| `native_name` columns so an operator can edit them.
| @see docs/001_Architecture.md §"Enums vs lookup tables"
*/

return [

    'Status' => [
        'draft' => 'Draft',
        'active' => 'Active',
        'inactive' => 'Inactive',
        'pending' => 'Pending',
        'suspended' => 'Suspended',
        'archived' => 'Archived',
    ],

    'UserType' => [
        'admin' => 'Administrator',
        'seller' => 'Seller',
        'customer' => 'Customer',
    ],

    'StoreStatus' => [
        'pending' => 'Pending',
        'under_review' => 'Under Review',
        'approved' => 'Approved',
        'active' => 'Active',
        'suspended' => 'Suspended',
        'rejected' => 'Rejected',
        'closed' => 'Closed',
    ],

    'OrganizationStatus' => [
        'pending' => 'Pending Review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'suspended' => 'Suspended',
        'archived' => 'Archived',
    ],

    /*
    | Roles WITHIN one organization (ADR-030) — not to be confused with the
    | platform's Spatie roles: these mean something only inside a company.
    | Ownership changes by transfer alone (ADR-029).
    */
    'OrganizationRole' => [
        'owner' => 'Owner',
        'manager' => 'Manager',
        'finance' => 'Finance',
        'warehouse' => 'Warehouse',
        'support' => 'Support',
        'marketing' => 'Marketing',
        'editor' => 'Editor',
        'viewer' => 'Viewer',
    ],

    'OrganizationMemberStatus' => [
        'active' => 'Active',
        'suspended' => 'Suspended',
    ],

    'InvitationStatus' => [
        'pending' => 'Pending',
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
        'expired' => 'Expired',
        'cancelled' => 'Withdrawn',
    ],

    'StoreOpeningRequestStatus' => [
        'draft' => 'Draft',
        'pending' => 'Pending Review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
    ],

    'OrganizationDocumentStatus' => [
        'pending' => 'Pending Review',
        'approved' => 'Approved',
        'needs_revision' => 'Needs Revision',
        'rejected' => 'Rejected',
    ],

    'OrganizationDocumentType' => [
        'tax_certificate' => 'Tax certificate',
        'trade_registry' => 'Trade registry document',
        'signature_circular' => 'Signature circular',
        'id_document' => 'Identity document',
        'other' => 'Other',
    ],

    /*
    | App\Modules\Offer\Domain\Enums\OfferStatus and the Sprint-0 placeholder
    | App\Shared\Enums\OfferStatus resolve here together. The union of both
    | case sets: `suspended` is the module enum's (§2.2); `draft`, `pending`,
    | `rejected` and `expired` are the placeholder's alone.
    |
    | So is `out_of_stock`. The module enum has no such case and never will —
    | out-of-stock is derived from `stock_quantity = 0` (ADR-043/045). The label
    | stays for the placeholder; it must not be read as an offer status.
    */
    'OfferStatus' => [
        'draft' => 'Draft',
        'pending' => 'Pending Approval',
        'active' => 'Live',
        'paused' => 'Paused',
        'suspended' => 'Suspended',
        'out_of_stock' => 'Out of Stock',
        'rejected' => 'Rejected',
        'expired' => 'Expired',
        'withdrawn' => 'Withdrawn',
    ],

    /*
    | Order lifecycle (ADR-054). Three states, because payment and shipping belong
    | to later sprints: `pending` means stock is HELD, `awaiting_payment` means it
    | has been COMMITTED and the customer owes for it.
    */
    'OrderStatus' => [
        'pending' => 'In checkout',
        'awaiting_payment' => 'Awaiting payment',
        'paid' => 'Paid',
        'delivered' => 'Delivered',
        'refunded' => 'Refunded',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
    ],

    /*
    | WHY a stock movement happened (ADR-050) — the column that answers "why did
    | my stock change?". Three fewer could be three sold or three sitting in
    | somebody's basket, and only the type says which.
    */
    'StockMovementType' => [
        'seller_adjustment' => 'Seller entry',
        'reserved' => 'Reserved',
        'released' => 'Released',
        'committed' => 'Sold',
        'restocked' => 'Returned to stock',
    ],

    'ReservationStatus' => [
        'active' => 'Held',
        'released' => 'Released',
        'committed' => 'Became a sale',
        'restocked' => 'Refunded',
    ],

    /*
    | Keyed by the enum's SHORT class name, so the module-owned
    | App\Modules\Catalog\Domain\Enums\ProductStatus and the Sprint-0
    | placeholder App\Shared\Enums\ProductStatus resolve here together. The
    | union of both case sets: `needs_revision` is the Catalog lifecycle's
    | (§2.6), `unpublished` is the placeholder's and the Catalog enum has no
    | such state.
    */
    'ProductStatus' => [
        'draft' => 'Draft',
        'pending_review' => 'In review',
        'needs_revision' => 'Needs revision',
        'published' => 'Published',
        'unpublished' => 'Unpublished',
        'rejected' => 'Rejected',
        'archived' => 'Archived',
    ],

    'AttributeType' => [
        'select' => 'Select',
        'text' => 'Text',
        'number' => 'Number',
        'boolean' => 'Yes/No',
    ],

    'NotificationType' => [
        'database' => 'In-app',
        'mail' => 'Email',
        'sms' => 'SMS',
        'push' => 'Push',
        'broadcast' => 'Broadcast',
    ],

    'MediaType' => [
        'image' => 'Image',
        'document' => 'Document',
        'video' => 'Video',
        'audio' => 'Audio',
        'archive' => 'Archive',
        'other' => 'Other',
    ],

    'ActivityType' => [
        'login' => 'Signed in',
        'logout' => 'Signed out',
        'login_failed' => 'Failed sign-in attempt',
        'password_changed' => 'Password changed',
        'password_reset' => 'Password reset',
        'email_verified' => 'Email verified',
        'profile_updated' => 'Profile updated',
        'permission_changed' => 'Permissions changed',
        'role_changed' => 'Role changed',
        'two_factor_enabled' => 'Two-factor authentication enabled',
        'two_factor_disabled' => 'Two-factor authentication disabled',
        'session_revoked' => 'Session revoked',
        'device_trusted' => 'Device marked as trusted',
        'settings_updated' => 'Settings updated',
    ],

    'SettingGroup' => [
        'general' => 'General',
        'company' => 'Company',
        'email' => 'Email',
        'sms' => 'SMS',
        'media' => 'Media',
        'seo' => 'SEO',
        'localization' => 'Localization',
        'security' => 'Security',
        'performance' => 'Performance',
        'shipping' => 'Shipping',
        'system' => 'System',
    ],

    'SettingType' => [
        'string' => 'Text',
        'integer' => 'Number',
        'boolean' => 'Yes/No',
        'json' => 'JSON',
        'text' => 'Long text',
    ],

    'TextDirection' => [
        'ltr' => 'Left to right',
        'rtl' => 'Right to left',
    ],

    'SymbolPosition' => [
        'before' => 'Before the amount',
        'after' => 'After the amount',
    ],

    'CancellationRequestStatus' => [
        'pending' => 'Awaiting answer',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ],

    'QuestionStatus' => [
        'pending' => 'Awaiting answer',
        'answered' => 'Answered',
    ],

    'ReviewStatus' => [
        'pending_review' => 'Awaiting approval',
        'published' => 'Published',
        'rejected' => 'Rejected',
    ],

    'ShipmentStatus' => [
        'pending' => 'Preparing',
        'shipped' => 'In transit',
        'delivered' => 'Delivered',
        'returned' => 'Returned',
        'cancelled' => 'Cancelled',
    ],

    'DeliveredVia' => [
        'buyer' => 'Buyer confirmed',
        'transit_sweep' => 'Transit window elapsed',
        'carrier' => 'Carrier reported',
    ],
];
