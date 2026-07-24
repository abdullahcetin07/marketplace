<?php

declare(strict_types=1);

/*
| Activity timeline sentences, resolved by ActivityEntry::label().
|
| Keyed by ActivityType value, with a few extras for entries that store an
| explicit description. Written in the second person — a user reads these on
| their own security page.
|
| @see App\Modules\Activity\Domain\Models\ActivityEntry::label()
*/

return [

    'login' => 'You signed in',
    'logout' => 'You signed out',
    'login_failed' => 'Failed sign-in attempt',
    'suspicious_login' => 'A suspicious sign-in attempt was detected on your account',
    'password_changed' => 'Your password was changed',
    'password_reset' => 'Your password was reset',
    'email_verified' => 'Your email address was verified',
    'profile_updated' => 'Your profile was updated',
    'permission_changed' => 'Your permissions were changed',
    'role_changed' => 'Your role was changed',
    'two_factor_enabled' => 'Two-factor authentication was enabled',
    'two_factor_disabled' => 'Two-factor authentication was disabled',
    'session_revoked' => 'A session was revoked',
    'device_trusted' => 'A device was marked as trusted',
    'settings_updated' => 'Settings were updated',

    'account_created' => 'Account created',
    'password_reset_requested' => 'A password reset was requested',

];
