<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\UserType;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;

/**
 * Merchant account. Authenticates against the `seller` guard and reaches only
 * the /seller Filament panel.
 *
 * Roles for this guard: Seller (account owner), Seller Employee (delegated
 * staff with a narrower permission set).
 *
 * NOTE: the link from a Seller to the Store(s) they operate is introduced with
 * the Store module in a later sprint. Sprint 0 deliberately stops at identity.
 *
 * @see Database\Seeders\RolePermissionSeeder
 */
class Seller extends User implements FilamentUser, HasName
{
    protected $table = 'users';

    public static function actorType(): ?UserType
    {
        return UserType::Seller;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->canAccessPanelId($panel->getId());
    }

    public function getFilamentName(): string
    {
        // Computed, never stored (ADR-012). @see User::displayName()
        return $this->display_name;
    }

    /**
     * Whether this account is the owner rather than a delegated employee.
     */
    public function isAccountOwner(): bool
    {
        return $this->hasRole(config('marketplace.roles.seller'), UserType::Seller->guard());
    }
}
