<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\UserType;

/**
 * Storefront shopper. Authenticates against the `customer` guard.
 *
 * Customers have no Filament panel by design — they are served entirely by the
 * Next.js frontend over the Sanctum-protected API. UserType::Customer->panel()
 * returns null, which is what stops a customer session from ever satisfying a
 * panel route.
 *
 * @see Database\Seeders\RolePermissionSeeder
 */
class Customer extends User
{
    protected $table = 'users';

    public static function actorType(): ?UserType
    {
        return UserType::Customer;
    }
}
