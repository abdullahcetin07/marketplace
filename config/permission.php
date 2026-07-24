<?php

declare(strict_types=1);

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return [

    'models' => [
        'permission' => Permission::class,
        'role' => Role::class,
    ],

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles',
        'role_has_permissions' => 'role_has_permissions',
    ],

    'column_names' => [
        'role_pivot_key' => null,
        'permission_pivot_key' => null,

        /*
        | Polymorphic key on the pivot tables. All three actor types live in
        | `users`, so this holds a users.id and model_type holds the concrete
        | subclass (App\Models\Admin, App\Models\Seller, ...).
        */
        'model_morph_key' => 'model_id',

        'team_foreign_key' => 'team_id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Direct Permissions
    |--------------------------------------------------------------------------
    |
    | Enabled. Roles are the normal way to grant access, but a marketplace
    | always ends up needing one-off grants ("this seller may bulk-import"),
    | and the alternative — inventing a single-member role — is worse.
    |
    */

    'register_permission_check_method' => true,

    /*
    |--------------------------------------------------------------------------
    | Octane / Long-Running Workers
    |--------------------------------------------------------------------------
    |
    | Reset the in-memory permission cache between requests so a worker that
    | served an admin does not answer the next request with the admin's
    | resolved permissions.
    |
    */

    'register_octane_reset_listener' => true,

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    |
    | On: role and permission changes are business-significant and are picked
    | up by the audit log subscriber.
    |
    */

    'events_enabled' => true,

    'teams' => false,

    'team_resolver' => \Spatie\Permission\DefaultTeamResolver::class,

    'use_passport_client_credentials' => false,

    'display_permission_in_exception' => false,

    'display_role_in_exception' => false,

    /*
    |--------------------------------------------------------------------------
    | Wildcard Permissions
    |--------------------------------------------------------------------------
    |
    | OFF, deliberately.
    |
    | Wildcards ("store.*") look convenient and are a security hazard: granting
    | `store.*` today silently grants `store.force_delete` the moment someone
    | adds that permission. Every permission a role holds must be an explicit,
    | reviewable decision — which is exactly what PermissionRegistry makes
    | cheap enough to be practical.
    |
    */

    'enable_wildcard_permission' => false,

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Permission lookups happen on nearly every request; without this cache
    | each one is two extra queries. 24h expiry is safe because the cache is
    | flushed automatically whenever a role or permission changes.
    |
    | The cache store is pinned to Redis rather than 'default' so a switch to
    | an untagged cache store cannot break invalidation.
    |
    */

    'cache' => [
        'expiration_time' => \DateInterval::createFromDateString('24 hours'),
        'key' => 'spatie.permission.cache',
        'store' => env('PERMISSION_CACHE_STORE', 'default'),
    ],

];
