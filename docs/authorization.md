# Authorization

Two rules, and everything else follows from them:

1. **Permissions are dynamic and derived.** Nobody hand-maintains a list.
2. **Roles are referenced by name. Never by id.**

---

## Why never by id

A role id is assigned by the database. It differs between local, staging and
production. It changes on every reseed. It means nothing.

Code containing `Role::find(3)` is code that breaks the first time production is
reseeded, and breaks *silently* — id 3 still exists, it is just a different role
now. That is a privilege bug that no test catches, because the test database
happens to have the same ordering.

Everywhere a role is named:

```php
config('marketplace.roles.super_admin')   // 'Admin'
$user->assignRole(config('marketplace.roles.seller'));
Role::findOrCreate($name, $guard);
```

`config/marketplace.php` is the one place the strings live, so a rename is a
one-line change.

---

## Why permissions are derived, not listed

`App\Shared\Support\PermissionRegistry` is the single source of truth. A module
registers a *resource*, and the registry expands it into the standard verb set:

```php
PermissionRegistry::resource('store', [UserType::Admin, UserType::Seller]);
// →  store.view_any, store.view, store.create, store.update,
//    store.delete, store.restore, store.force_delete
//    ... created separately for the admin guard and the seller guard
```

Consequences that matter:

- Adding a module is **one registration line**, not twelve seeder rows.
- `BasePolicy` *computes* the permission name from the model, so no policy ever
  repeats a string literal that could typo its way into granting access.
- `php artisan marketplace:sync-permissions` is idempotent, so it runs safely on
  every deploy.
- A permission cannot exist in a policy but be missing from the database,
  because both derive from the same registry.

Non-CRUD abilities are registered individually:

```php
PermissionRegistry::ability('order.refund', [UserType::Admin]);
```

---

## Guard scoping

Every permission and role row carries a `guard_name`, and the unique indexes are
on `(name, guard_name)`.

This means `store.update` exists **twice** — once for the admin guard, once for
the seller guard — and they are different permissions with different meanings.
An admin's grant can never be accidentally satisfied by a seller's permission
row.

`User::guardName()` returns `$this->type->guard()`, so the correct scope is
always used without any call site having to remember it.

---

## Wildcards are disabled

`config/permission.php` sets `enable_wildcard_permission => false`.

Wildcards look convenient and are a security hazard: granting `store.*` today
silently grants `store.force_delete` the moment someone adds that permission
next sprint. Every permission a role holds must be an explicit, reviewable
decision — which is only practical because the registry makes creating them
cheap.

---

## Policies

`BasePolicy` implements the standard abilities once. A concrete policy supplies
two things:

```php
final class StorePolicy extends BasePolicy
{
    protected function permissionPrefix(): string
    {
        return 'store';
    }

    // Any policy reachable from the seller panel MUST override this.
    protected function owns(User $user, Model $model): bool
    {
        return $model->seller_id === $user->getKey();
    }
}
```

The decision sequence in `allowIf()`:

1. **Super-admin?** → allow (via `before()`).
2. **Holds the permission on their own guard?** → if not, deny.
3. **Is an admin?** → allow (admins act platform-wide).
4. **Ability requires ownership and they do not own it?** → deny.
5. → allow.

That layering is what lets an admin and a seller both hold `offer.update` and
mean different things by it: the admin may edit any offer, the seller only their
own.

`owns()` defaults to `false`, which is correct for admin-only resources but
means **a policy exposed to sellers that forgets to override it will deny
everything** — a loud failure, not a silent grant.

`forceDelete` is additionally restricted to admins regardless of permission: an
irreversible action should not be reachable from a seller panel.

---

## Roles

| Role | Guard | Holds |
|---|---|---|
| Admin | `admin` | Every admin-guard permission (super-admin) |
| Editor | `admin` | Panel access, read-only user visibility, catalogue editing (Sprint 1+) |
| Category Manager | `admin` | Panel access, taxonomy ownership (Sprint 1+) |
| Seller | `seller` | Every seller-guard permission, scoped by ownership |
| Seller Employee | `seller` | Panel access; narrower, no deletes |
| Customer | `customer` | None yet — customer actions are public or ownership-gated |

Editor and Category Manager hold little today because their meaningful
permissions belong to modules that do not exist yet. The roles are seeded now so
they can be assigned from day one and so the permission attachment is a one-line
edit when the modules land.

Seeded by `Database\Seeders\RolePermissionSeeder`, which is idempotent and safe
on every deploy. Note it uses `syncPermissions()`, so **removing** a permission
from a role in that file actually removes it in production.

---

## Auditing

`AuthServiceProvider` registers a `Gate::after` hook that logs every denial to
the `audit` channel with the user, guard, ability and correlation id.

Denials are the earliest signal of both a misconfigured role and a probing
attacker, and they are otherwise invisible — the user just sees a 403.

---

## Operational commands

```bash
make permissions                                  # create missing permissions
php artisan marketplace:sync-permissions --dry-run # report only
php artisan marketplace:sync-permissions --prune   # remove undeclared (asks first)
```

`sync` never deletes. Orphans are reported, because dropping a permission a role
still holds is a decision a human makes.
