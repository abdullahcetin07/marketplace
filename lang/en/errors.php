<?php

declare(strict_types=1);

/*
| Domain error messages, keyed by BaseException::getErrorCode() and by the
| deny() reasons returned from policies.
|
| Shown to end users — say what went wrong without revealing internal
| structure.
|
| @see App\Core\Domain\Exceptions\BaseException::userMessage()
*/

return [

    // Generic
    'generic' => 'Something went wrong. Please try again.',
    'validation_failed' => 'The submitted data is not valid.',
    'unauthenticated' => 'You must be signed in to do that.',
    'forbidden' => 'You are not allowed to do that.',
    'missing_permission' => 'You do not have the permission required for this action.',
    'not_owner' => 'You may only act on your own records.',
    'not_found' => 'Record not found.',
    'too_many_requests' => 'Too many requests. Please wait a moment.',

    // Identity
    'account_suspended' => 'Your account has been suspended. Please contact support.',
    'account_unverified' => 'Please verify your email address before continuing.',
    'invalid_credentials' => 'Those credentials do not match our records.',
    'two_factor_required' => 'A two-factor authentication code is required.',
    'two_factor_invalid' => 'That verification code is not valid.',
    'cannot_delete_self' => 'You cannot delete your own account.',
    'cannot_impersonate_self' => 'You cannot impersonate yourself.',
    'cannot_impersonate_admin' => 'Administrator accounts cannot be impersonated.',
    'cannot_modify_super_admin' => 'You cannot act on a super administrator account.',
    // You may grant and revoke up to your own level, never above it.
    'cannot_grant_role' => 'You cannot grant the role: :role',
    'session_not_found' => 'That session no longer exists or has already been revoked.',
    // One reason for every failure mode — expired, used, wrong address — so a
    // guessed token cannot confirm an address exists.
    'reset_token_invalid' => 'This password reset link is invalid or has expired. Please request a new one.',
    // One reason for every failure — a guessed UUID cannot confirm an account.
    'email_verification_invalid' => 'This verification link is invalid or has expired. Please request a new one.',
    'two_factor_already_enabled' => 'Two-factor authentication is already enabled. Disable it first to re-enrol.',

    // Localization
    'default_language_undeletable' => 'The default language cannot be deleted. Promote another language first.',
    'default_currency_undeletable' => 'The default currency cannot be deleted. Promote another currency first.',
    'stale_exchange_rate' => 'The exchange rate is out of date, so this cannot be priced.',

    // Settings
    'setting_locked' => 'This setting is locked by the system and cannot be changed.',
    'setting_undeletable' => 'Settings cannot be deleted.',
    'setting_uncreatable' => 'Settings cannot be created from the interface.',

    // Audit / Activity
    'audit_immutable' => 'Audit records cannot be modified or deleted.',
    'activity_immutable' => 'Activity records cannot be modified or deleted.',

    // Search / Media
    'search_indexing_failed' => 'The search index could not be updated.',
    'media_type_not_allowed' => 'That file type is not accepted.',
    'media_too_large' => 'That file exceeds the maximum allowed size.',

    // Store
    // One message for a missing slug AND a non-live store, so existence never leaks.
    'store_unavailable' => 'This store is not available.',

];
