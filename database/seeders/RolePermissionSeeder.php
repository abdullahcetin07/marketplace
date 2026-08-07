<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Shared\Enums\UserType;
use App\Shared\Support\PermissionRegistry;
use Illuminate\Database\Seeder;
use RuntimeException;
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
            // Reads the catalog to maintain its copy; does not own the taxonomy
            // and does not moderate — those are the Category Manager's.
            'catalog.products.view_any',
            /*
            | The two OVERSIGHT areas, so `user.view_any` keeps meaning
            | something in the panel and not only over the API — the account
            | split gates each area on its own ability (Identity §12.1).
            | NOT user.manage_staff: reading an account to maintain content is
            | not the same power as provisioning a colleague.
            */
            'user.oversee_sellers',
            'user.oversee_customers',
            ...PermissionRegistry::forResource('user', ['view_any', 'view']),
            ...PermissionRegistry::forResource('translation', ['view_any', 'view', 'update']),
            ...PermissionRegistry::forResource('setting', ['view_any', 'view']),
            /*
            | REVIEW MODERATION IS THE EDITOR'S (ADR-068) — and the exception to
            | the line above about not moderating. That line is about the
            | CATALOGUE, where a rejection sends a seller's listing back for
            | revision and belongs with the Category Manager who owns the
            | taxonomy. A review is neither taxonomy nor a listing: it is user
            | text and photographs, which is exactly what the content role reads
            | all day.
            |
            | Admin holds these too, from its own broad grant — no `review.*` is
            | in the excluded list above.
            */
            ...PermissionRegistry::forResource('review', ['view_any', 'view']),
            'review.moderate',
        ]);

        /*
        | Category Manager — taxonomy ownership, and AS OF THE CATALOG SPRINT
        | the role finally does the job ADR-013 reserved it for. It owns the
        | category tree, the attribute schema and the brands (ADR-038), and it
        | runs the product moderation queue day to day (§5).
        |
        | Moderation is granted here as well as to Admin deliberately: this is
        | the role that does it, and an Admin holding it too is a convenience,
        | not the intended workflow. Nothing about accounts, money or platform
        | settings — a taxonomy editor is not an administrator.
        */
        $this->role('category_manager', $guard)->syncPermissions([
            'panel.admin.access',
            'catalog.taxonomy.manage',
            'catalog.products.moderate',
            'catalog.products.view_any',
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
            /*
            | The two OVERSIGHT areas of the admin panel, and deliberately not
            | the staff one: a helpdesk answers merchants' and shoppers'
            | tickets, it does not provision colleagues or grant staff roles.
            | @see App\Modules\Identity\Presentation\Policies\UserPolicy
            */
            'user.oversee_sellers',
            'user.oversee_customers',
            /*
            | Offers, read-only. A helpdesk answering "why is my listing not
            | showing?" needs to see the offer and its status; pulling it is a
            | different power and stays with Admin (ADR-044).
            */
            'offer.view_any',
            'offer.view',
            /*
            | Stock, read-only. "Why does the site say sold out when I have ten?"
            | is a support question, and answering it needs to see on-hand,
            | reserved and the movement history. There is no write permission to
            | withhold — nobody edits a merchant's stock (§7).
            */
            'inventory.view_any',
            'inventory.view',
            /*
            | Orders, read-only. "Where is my order?" is the single most common
            | ticket a marketplace takes, and answering it needs the order, its
            | lines and its status. NOT `order.cancel`: cancelling releases or
            | strands somebody's stock and creates a refund obligation once
            | Payment exists — that is an Admin decision, not a helpdesk one.
            */
            'order.view_any',
            'order.view',
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
            /*
            | Orders, read-only (ADR-055). Reconciliation reads what was charged
            | and what of it was KDV — the breakdown lives on the order lines and
            | nowhere else. Finance does not cancel: undoing a sale is an
            | operational decision with a stock consequence, and it belongs to
            | whoever is answering the customer.
            */
            'order.view_any',
            'order.view',
            'audit.view_any',
            'audit.view',
            'audit.export',
            /*
            | The two OVERSIGHT areas — reconciliation reads accounts, and
            | `user.view_any` alone no longer opens one in the panel (Identity
            | §12.1). NOT user.manage_staff: finance reads accounts, it does not
            | provision colleagues or grant staff roles.
            */
            'user.oversee_sellers',
            'user.oversee_customers',
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
            // Catalog authoring IS the delegated employee's job — opening
            // products is merchandising, not a financial or legal act, and
            // withholding it would leave the owner personally filing every
            // product. Confined to their own organization's proposals by
            // ProductPolicy::owns() exactly as it confines the owner.
            'catalog.products.view_any',
            'catalog.products.create',
            'catalog.products.author',
            ...PermissionRegistry::forResource('session', ['view_any', 'view', 'delete']),
            /*
            | ANSWERING BUYER QUESTIONS IS DELEGABLE STAFF WORK (ADR-071), like
            | product authoring above it: a shopper waiting on "kutusundan kablo
            | çıkıyor mu?" should not wait for the owner personally. It is still
            | confined to the organization's own stores — `QuestionPolicy::answer()`
            | checks the question's target against the actor's live stores, exactly
            | as it does for the owner.
            */
            ...PermissionRegistry::forResource('question', ['view_any', 'view']),
            'question.answer',
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
            throw new RuntimeException("Role name not configured: marketplace.roles.{$configKey}");
        }

        return Role::findOrCreate($name, $guard);
    }
}
