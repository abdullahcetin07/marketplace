<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Shared\Enums\UserType;
use App\Shared\Support\PermissionRegistry;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the nine platform roles and attaches their permissions.
 *
 * TWO RULES THIS SEEDER EXISTS TO ENFORCE:
 *
 *  1. Roles are addressed by NAME, from config('marketplace.roles.*'). No id
 *     is ever written down, read back, or assumed. Ids differ per environment
 *     and change on every reseed.
 *
 *  2. Permissions are DERIVED from PermissionRegistry, not typed out here.
 *     Modules register their resources in their service providers and the
 *     permissions appear on the next run — no seeder edit, no risk of a
 *     permission existing in a policy but never in the database.
 *
 * Idempotent: safe to run on every deploy. `syncPermissions()` means removing
 * a permission from a role here actually removes it in production.
 *
 * @see App\Shared\Support\PermissionRegistry
 * @see docs/authorization.md
 */
final class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        PermissionRegistry::sync();

        $this->seedAdminRoles();
        $this->seedSellerRoles();
        $this->seedCustomerRoles();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seedAdminRoles(): void
    {
        $guard = UserType::Admin->guard();
        $all = PermissionRegistry::forGuard($guard);

        /*
        | Super Admin — the platform owner. BasePolicy::before() short-circuits
        | for this role, so the attached permissions are belt-and-braces: they
        | keep the admin UI rendering correctly and mean removing the role does
        | not silently remove all access.
        |
        | DISTINCT FROM Admin, which is the change Sprint 1 makes to Sprint 0's
        | model. An unauditable bypass should belong to as few people as
        | possible; "broad access" is a different need and now has its own role.
        */
        $this->role('super_admin', $guard)->syncPermissions($all);

        /*
        | Admin — broad operational access, but NOT the bypass. Everything
        | except the abilities that can alter the platform's own security
        | posture or impersonate a user.
        */
        $this->role('admin', $guard)->syncPermissions(array_values(array_diff($all, [
            'user.impersonate',
            'user.disable_two_factor',
            'setting.manage_restricted',
            // Security alerts route to the responders (Super Admin + Support),
            // not to every admin (Q6). Held out of the broad grant so alerting
            // scales without spamming hundreds of admins; grant it explicitly to
            // whoever should be paged.
            'security.receive_alerts',
        ])));

        /*
        | Editor — content maintenance. Reads everything, edits catalogue
        | content (arriving with the Catalogue module), touches no accounts.
        */
        $this->role('editor', $guard)->syncPermissions([
            'panel.admin.access',
            ...PermissionRegistry::forResource('user', ['view_any', 'view']),
            ...PermissionRegistry::forResource('translation', ['view_any', 'view', 'update']),
            ...PermissionRegistry::forResource('setting', ['view_any', 'view']),
        ]);

        /*
        | Category Manager — taxonomy ownership. Its meaningful permissions
        | arrive with the Catalogue module; today it holds panel access and
        | read-only visibility so the role exists and can be assigned.
        */
        $this->role('category_manager', $guard)->syncPermissions([
            'panel.admin.access',
            ...PermissionRegistry::forResource('translation', ['view_any', 'view']),
        ]);

        /*
        | Support — helpdesk. Can read accounts and their activity to answer a
        | ticket, and can trigger a password reset. Deliberately CANNOT
        | impersonate or clear 2FA: those are the two actions an attacker with
        | helpdesk access would use to take an account over, and they belong to
        | a smaller group.
        */
        $this->role('support', $guard)->syncPermissions([
            'panel.admin.access',
            'user.reset_password',
            'user.view_login_history',
            // Support are the front line for account-takeover reports, so they
            // receive the platform security alerts (Q6).
            'security.receive_alerts',
            'activity.view',
            'activity.view_any',
            ...PermissionRegistry::forResource('user', ['view_any', 'view']),
            ...PermissionRegistry::forResource('session', ['view_any', 'view', 'delete']),
        ]);

        /*
        | Finance — money. Currency rates and the audit trail (which is what
        | reconciliation reads). Commission and payout permissions arrive with
        | those modules.
        */
        $this->role('finance', $guard)->syncPermissions([
            'panel.admin.access',
            'currency.update_rates',
            'audit.view_any',
            'audit.view',
            'audit.export',
            ...PermissionRegistry::forResource('currency', ['view_any', 'view', 'update']),
            ...PermissionRegistry::forResource('user', ['view_any', 'view']),
        ]);
    }

    private function seedSellerRoles(): void
    {
        $guard = UserType::Seller->guard();

        /*
        | Seller — the merchant account owner. Holds every seller-guard
        | permission; the ownership check in BasePolicy::owns() is what confines
        | that to their own records.
        */
        $this->role('seller', $guard)->syncPermissions(PermissionRegistry::forGuard($guard));

        /*
        | Seller Employee — delegated staff. Narrower: manages their own
        | sessions and sees their own activity, but holds no delete verbs.
        | Store financial and legal settings arrive with the Store module and
        | are deliberately withheld here.
        */
        $this->role('seller_employee', $guard)->syncPermissions([
            'panel.seller.access',
            'activity.view',
            ...PermissionRegistry::forResource('session', ['view_any', 'view', 'delete']),
        ]);
    }

    private function seedCustomerRoles(): void
    {
        $guard = UserType::Customer->guard();

        /*
        | Customer — assigned on registration. Manages their own sessions and
        | devices and reads their own activity feed; everything else a customer
        | may do is either public or gated by ownership rather than by a
        | permission.
        |
        | `device.*` mirrors `session.*`: Identity §12 registers devices for all
        | three actor types, ownership-scoped, and DevicePolicy gates the
        | customer-facing /devices endpoints on them. Without them here no
        | customer could list, trust or forget their own devices at all — and
        | the ownership scoping DevicePolicy exists for was never reached.
        | `update` is the trust action; there is no create verb (a device is
        | recognised at login, never created by hand).
        */
        $this->role('customer', $guard)->syncPermissions([
            'activity.view',
            ...PermissionRegistry::forResource('session', ['view_any', 'view', 'delete']),
            ...PermissionRegistry::forResource('device', ['view_any', 'view', 'update', 'delete']),
        ]);
    }

    /**
     * Resolve or create a role by its configured NAME. Never by id.
     */
    private function role(string $configKey, string $guard): Role
    {
        $name = config("marketplace.roles.{$configKey}");

        if (! is_string($name) || $name === '') {
            throw new \RuntimeException("Role name not configured: marketplace.roles.{$configKey}");
        }

        return Role::findOrCreate($name, $guard);
    }
}
