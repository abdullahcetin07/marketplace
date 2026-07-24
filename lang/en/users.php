<?php

declare(strict_types=1);

/*
| Labels for the admin panel's User resource (Filament). Presentation strings
| only — the behaviour lives in the Identity Actions.
|
| @see App\Modules\Identity\Presentation\Filament\Resources\UserResource
*/

return [
    'singular' => 'User',
    'plural' => 'Users',

    'name' => 'Name',
    'first_name' => 'First name',
    'last_name' => 'Last name',
    'email' => 'Email',
    'phone' => 'Phone',
    'type' => 'Type',
    'status' => 'Status',
    'status_active' => 'Active',
    'status_suspended' => 'Suspended',
    'two_factor' => '2FA',
    'last_login' => 'Last sign-in',
    'registered' => 'Registered',

    'reason' => 'Reason',
    'reason_help' => 'Recorded in the audit trail. Explain why this change was made.',

    'reset_password' => 'Reset password',
    'disable_two_factor' => 'Disable 2FA',
];
