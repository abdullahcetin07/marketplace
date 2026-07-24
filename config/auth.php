<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Seller;

return [

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    |
    | There is deliberately NO meaningful default guard. Every actor type has
    | its own guard, and code that relies on "the default" is code that has not
    | decided which actor it is talking about. `auth()->user()` without an
    | explicit guard is a bug in this codebase — use current_actor() or name
    | the guard.
    |
    | 'customer' is set as the default only because Laravel requires a value
    | (password broker resolution, `Auth::routes()` internals).
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'customer'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'customers'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Guards — three independent session guards plus the API guard
    |--------------------------------------------------------------------------
    |
    | Each guard resolves a DIFFERENT model. Because Admin, Seller and Customer
    | each apply a global scope on `users.type`, a seller's session cookie can
    | never authenticate against the admin guard: the admin provider queries
    | `where type = 'admin'` and simply will not find the row.
    |
    | That is the whole isolation mechanism, and it is enforced at the query
    | level rather than by a check someone can forget to write.
    |
    | Guard names match UserType values exactly — see App\Shared\Enums\UserType.
    |
    */

    'guards' => [

        'admin' => [
            'driver' => 'session',
            'provider' => 'admins',
        ],

        'seller' => [
            'driver' => 'session',
            'provider' => 'sellers',
        ],

        'customer' => [
            'driver' => 'session',
            'provider' => 'customers',
        ],

        /*
        | The Next.js storefront authenticates customers via Sanctum. Token
        | abilities are issued from the customer's permissions, so the API
        | surface a token can reach is never wider than the session it came
        | from.
        */
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'customers',
        ],

        /*
        | Filament's own scaffolding occasionally reaches for a guard named
        | 'web'. Pointing it at admins keeps that path from silently falling
        | back to an unscoped provider.
        */
        'web' => [
            'driver' => 'session',
            'provider' => 'admins',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    */

    'providers' => [

        'admins' => [
            'driver' => 'eloquent',
            'model' => Admin::class,
        ],

        'sellers' => [
            'driver' => 'eloquent',
            'model' => Seller::class,
        ],

        'customers' => [
            'driver' => 'eloquent',
            'model' => Customer::class,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Password Reset Brokers
    |--------------------------------------------------------------------------
    |
    | One broker per actor type, all sharing the single password_reset_tokens
    | table. Separate brokers mean an admin reset link cannot be redeemed
    | against a customer account even if the email addresses match.
    |
    | Admin tokens expire in 15 minutes rather than 60 — a privileged reset
    | link sitting in an inbox is a standing risk.
    |
    */

    'passwords' => [

        'admins' => [
            'provider' => 'admins',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 15,
            'throttle' => 60,
        ],

        'sellers' => [
            'provider' => 'sellers',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],

        'customers' => [
            'provider' => 'customers',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | How long before a user must re-confirm their password to reach a
    | sensitive area. Three hours.
    |
    */

    'password_timeout' => (int) env('AUTH_PASSWORD_TIMEOUT', 10800),

];
