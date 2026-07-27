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

    'OfferStatus' => [
        'draft' => 'Draft',
        'pending' => 'Pending Approval',
        'active' => 'Live',
        'paused' => 'Paused',
        'out_of_stock' => 'Out of Stock',
        'rejected' => 'Rejected',
        'expired' => 'Expired',
        'withdrawn' => 'Withdrawn',
    ],

    'ProductStatus' => [
        'draft' => 'Draft',
        'pending_review' => 'Pending Review',
        'published' => 'Published',
        'unpublished' => 'Unpublished',
        'rejected' => 'Rejected',
        'archived' => 'Archived',
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

];
