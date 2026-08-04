<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Shared\Enums\UserType;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;

/**
 * The single source of truth for what permissions exist.
 *
 * THE RULE THIS CLASS ENFORCES: permissions are dynamic and roles are never
 * referenced by id.
 *
 * Modules do not hand-write permission names into seeders. They register a
 * resource here — `PermissionRegistry::resource('store', [UserType::Admin,
 * UserType::Seller])` — and the registry expands it into the standard verb set
 * for each guard that needs it. Consequences:
 *
 *   * Adding a module means one registration line, not 12 seeder rows.
 *   * `php artisan permission:sync` is idempotent and safe to run on deploy.
 *   * Permission names are derivable, so BasePolicy can compute them instead
 *     of every policy repeating a string literal.
 *   * Nothing anywhere depends on a role's numeric id, which differs between
 *     environments and is meaningless as an identifier.
 *
 * Roles are referenced by NAME, via config('marketplace.roles.*') — see
 * config/marketplace.php and Database\Seeders\RolePermissionSeeder.
 *
 * @see docs/authorization.md
 */
final class PermissionRegistry
{
    /**
     * Verbs generated for every registered resource. They line up exactly with
     * the ability names BasePolicy implements.
     *
     * @var array<int, string>
     */
    public const array VERBS = [
        'view_any',
        'view',
        'create',
        'update',
        'delete',
        'restore',
        'force_delete',
    ];

    /**
     * Resource registrations, keyed by resource name.
     *
     * Sprint 0 registers only what exists. Business modules append to this map
     * from their own service providers via register().
     *
     * @var array<string, array<int, UserType>>
     */
    private static array $resources = [
        'user' => [UserType::Admin],
        'role' => [UserType::Admin],
        'permission' => [UserType::Admin],
    ];

    /**
     * Standalone permissions that do not follow the resource/verb pattern.
     *
     * @var array<string, array<int, UserType>>
     */
    private static array $abilities = [
        'panel.admin.access' => [UserType::Admin],
        'panel.seller.access' => [UserType::Seller],
        'system.horizon.view' => [UserType::Admin],
        'system.logs.view' => [UserType::Admin],
        'system.settings.manage' => [UserType::Admin],
    ];

    /**
     * Register a CRUD resource for one or more guards.
     *
     * Call from a module service provider's register() method.
     *
     * @param array<int, UserType> $actorTypes
     */
    public static function resource(string $name, array $actorTypes): void
    {
        self::$resources[$name] = $actorTypes;
    }

    /**
     * Register a single non-CRUD ability, e.g. `order.refund`.
     *
     * @param array<int, UserType> $actorTypes
     */
    public static function ability(string $name, array $actorTypes): void
    {
        self::$abilities[$name] = $actorTypes;
    }

    /**
     * Every permission name that should exist, grouped by guard.
     *
     * @return array<string, array<int, string>>
     */
    public static function all(): array
    {
        $byGuard = [];

        foreach (UserType::cases() as $type) {
            $byGuard[$type->guard()] = [];
        }

        foreach (self::$resources as $resource => $actorTypes) {
            foreach ($actorTypes as $type) {
                foreach (self::VERBS as $verb) {
                    $byGuard[$type->guard()][] = $resource.'.'.$verb;
                }
            }
        }

        foreach (self::$abilities as $ability => $actorTypes) {
            foreach ($actorTypes as $type) {
                $byGuard[$type->guard()][] = $ability;
            }
        }

        return array_map(
            static fn (array $names): array => array_values(array_unique($names)),
            $byGuard,
        );
    }

    /**
     * Permission names for one guard.
     *
     * @return array<int, string>
     */
    public static function forGuard(string $guard): array
    {
        return self::all()[$guard] ?? [];
    }

    /**
     * Names for a single resource on a single guard — used when composing a
     * role's permission set in the seeder.
     *
     * @param array<int, string>|null $only Restrict to these verbs.
     *
     * @return array<int, string>
     */
    public static function forResource(string $resource, ?array $only = null): array
    {
        $verbs = $only ?? self::VERBS;

        return array_map(
            static fn (string $verb): string => $resource.'.'.$verb,
            array_values(array_intersect($verbs, self::VERBS)),
        );
    }

    /**
     * @return array<int, string>
     */
    public static function resourceNames(): array
    {
        return array_keys(self::$resources);
    }

    /**
     * Create any missing permission rows and report what was created.
     *
     * Idempotent: existing permissions are left untouched, so this is safe in
     * a deploy pipeline. Permissions removed from the registry are reported by
     * orphans() but never auto-deleted — dropping a permission that a role
     * still references is a decision for a human.
     *
     * @return array<string, array<int, string>>
     */
    public static function sync(): array
    {
        $created = [];

        foreach (self::all() as $guard => $names) {
            foreach ($names as $name) {
                $permission = Permission::query()->firstOrCreate([
                    'name' => $name,
                    'guard_name' => $guard,
                ]);

                if ($permission->wasRecentlyCreated) {
                    $created[$guard][] = $name;
                }
            }
        }

        return $created;
    }

    /**
     * Permissions present in the database but no longer declared here.
     *
     * @return Collection<int, Permission>
     */
    public static function orphans(): Collection
    {
        $declared = self::all();

        return Permission::query()->get()->filter(
            static fn (Permission $permission): bool => ! in_array(
                $permission->name,
                $declared[$permission->guard_name] ?? [],
                true,
            ),
        )->values();
    }
}
