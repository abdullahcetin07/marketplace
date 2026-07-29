<?php

declare(strict_types=1);

/*
| Labels for the admin panel's account resources (Staff / Sellers / Customers).
| Presentation strings only — the behaviour lives in the Identity Actions.
|
| @see App\Modules\Identity\Presentation\Filament\Resources\AccountResource
*/

return [
    'singular' => 'User',
    'plural' => 'Users',

    // Three areas split by ACTOR TYPE — not one list with a filter.
    'staff' => [
        'singular' => 'Staff member',
        'plural' => 'Staff',
        'action' => [
            'create' => 'New staff member',
        ],
    ],
    'seller' => [
        'singular' => 'Seller',
        'plural' => 'Sellers',
    ],
    'customer' => [
        'singular' => 'Customer',
        'plural' => 'Customers',
    ],

    'name' => 'Name',
    'first_name' => 'First name',
    'last_name' => 'Last name',
    'email' => 'Email',
    'phone' => 'Phone',
    'type' => 'Type',
    'status' => 'Status',
    'status_active' => 'Active',
    'status_suspended' => 'Suspended',
    'status_draft' => 'Draft',
    'status_inactive' => 'Inactive',
    'status_pending' => 'Pending',
    'status_archived' => 'Archived',
    'two_factor' => '2FA',
    'last_login' => 'Last sign-in',
    'last_login_ip' => 'Last sign-in IP',
    'login_count' => 'Sign-ins',
    'registered' => 'Registered',
    'email_verified' => 'Email verified',
    'email_unverified' => 'Not verified',

    'password' => 'Password',
    'password_confirmation' => 'Confirm password',
    'password_help' => 'Staff password policy: at least 14 characters, mixed case, digits and symbols.',

    'roles' => 'Roles',
    'roles_none' => 'No roles',
    'roles_help' => 'Staff roles only. A seller’s team roles are managed by the seller, in their own panel.',

    'section' => [
        'profile' => 'Profile',
        'security' => 'Security',
    ],

    'login_history' => [
        'title' => 'Sign-in history',
        'at' => 'When',
        'result' => 'Successful',
        'failure_reason' => 'Failure reason',
        'ip' => 'IP address',
        'browser' => 'Browser',
        'platform' => 'Platform',
        'location' => 'Location',
        'empty' => 'No sign-in attempts recorded for this account.',
    ],

    'reason' => 'Reason',
    'reason_help' => 'Recorded in the audit trail. Explain why this change was made.',

    'action' => [
        'suspend' => 'Suspend',
        'suspend_confirm' => 'The account is suspended and the user cannot sign in. Nothing is deleted.',
        'reinstate' => 'Reinstate',
        'suspended_notice' => 'Account suspended.',
        'reinstated_notice' => 'Account reinstated.',
    ],

    'reset_password' => 'Reset password',
    'disable_two_factor' => 'Disable 2FA',
];
