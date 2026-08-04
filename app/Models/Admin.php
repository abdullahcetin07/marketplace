<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\UserType;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;

/**
 * Back-office operator. Authenticates against the `admin` guard and is the
 * only actor type able to reach the /admin Filament panel.
 *
 * Roles for this guard: Admin, Editor, Category Manager.
 *
 * @see Database\Seeders\RolePermissionSeeder
 */
class Admin extends User implements FilamentUser, HasName
{
    protected $table = 'users';

    public static function actorType(): ?UserType
    {
        return UserType::Admin;
    }

    /**
     * Filament calls this before rendering any page of a panel. Returning
     * false here is the last line of defence if a route somehow bypasses the
     * guard middleware.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->canAccessPanelId($panel->getId());
    }

    public function getFilamentName(): string
    {
        // Computed, never stored (ADR-012). @see User::displayName()
        return $this->display_name;
    }
}
