# Adding a business module

The Foundation module group is built. This is how a **business** module is
added on top of it.

Foundation is a module group of seven — Identity, Localization, Settings,
Audit, Activity, Media, Notification (ADR-002). This document describes the
pattern for adding an eighth, business-facing module; treat the `Store`
examples as the shape to follow, not as code to copy verbatim.

Business modules — Organization, Store, Product, Offer, Order, Payment — do not
exist yet and must not be created without an approved specification.

---

## Anatomy

A module mirrors the four Core layers:

```
app/Modules/Store/
  Domain/
    Store.php                     the model
    StoreRepositoryContract.php   port (extends Core's RepositoryContract)
    DTOs/CreateStoreDTO.php       DTOs — suffix DTO, never ...Data (ADR-021)
    Events/StoreApproved.php      domain events
    Exceptions/StoreNotApproved.php
  Application/
    StoreService.php
    Actions/ApproveStoreAction.php
    Jobs/ReindexStoreJob.php
  Infrastructure/
    StoreRepository.php           implements the port
    StoreObserver.php
  Presentation/
    StoreController.php
    StorePolicy.php
    StoreResource.php             API resource
    Requests/CreateStoreRequest.php
    Filament/                     panel resources
  StoreServiceProvider.php

database/Modules/Store/
  migrations/
  factories/

tests/Modules/Store/
  Unit/
  Feature/
```

---

## Steps

### 1. Register the service provider

Add it to `bootstrap/providers.php`. In its `register()`:

```php
PermissionRegistry::resource('store', [UserType::Admin, UserType::Seller]);
PermissionRegistry::ability('store.approve', [UserType::Admin]);
```

Then `make permissions` creates them. Nothing is hand-listed in a seeder.

In `boot()`, point migrations and factories at the module directory:

```php
$this->loadMigrationsFrom(database_path('Modules/Store/migrations'));
```

### 2. Bind the repository

```php
$this->app->bind(StoreRepositoryContract::class, StoreRepository::class);
```

Services type-hint the contract. That is what makes them unit-testable against
a fake.

### 3. Model

```php
final class Store extends Model implements \Spatie\MediaLibrary\HasMedia
{
    use HasUuid;      // public identifier
    use HasSlug;      // URL segment
    use HasSeo;       // jsonb metadata
    use HasCreator;   // provenance
    use HasUpdater;
    use HasMedia;     // S3-backed collections

    protected function casts(): array
    {
        return ['status' => StoreStatus::class];
    }
}
```

Migration needs the matching columns: `uuid` (unique), `slug` (unique),
`seo` (jsonb, nullable), `created_by`/`updated_by` (nullable FK to users).

### 4. Policy

```php
final class StorePolicy extends BasePolicy
{
    protected function permissionPrefix(): string { return 'store'; }

    protected function owns(User $user, Model $model): bool
    {
        return $model->seller_id === $user->getKey();
    }
}
```

**If the resource is reachable from the seller panel, you must override
`owns()`.** The default returns `false`, which denies everything — loudly, which
is the right failure direction.

### 5. Repository

```php
final class StoreRepository extends BaseRepository implements StoreRepositoryContract
{
    // Declared once here rather than rediscovered by whoever hits the
    // lazy-loading exception next.
    protected array $with = ['owner'];

    public function model(): string { return Store::class; }
}
```

### 6. Action, then service

One verb, one noun, owns its transaction:

```php
final class ApproveStoreAction extends BaseAction
{
    public function handle(Store $store, Admin $approver): Store
    {
        if (! $store->status->canTransitionTo(StoreStatus::Approved)) {
            throw StoreNotApproved::from($store->status);
        }

        $store->update(['status' => StoreStatus::Approved]);

        return $store;
    }

    // After COMMIT — side effects must not fire on a rolled-back transaction.
    protected function after(mixed $result, mixed ...$arguments): void
    {
        StoreApproved::dispatch($result->id);
    }
}
```

### 7. Tests

```
tests/Modules/Store/Unit/       state machine, DTOs — no database
tests/Modules/Store/Feature/    the action, the policy, the endpoint
```

The `Modules` suite is already declared in `phpunit.xml` and already gets
`RefreshDatabase` via `tests/Pest.php`.

---

## Rules the build enforces

| Rule | Enforced by |
|---|---|
| No module imports another module | `tests/Architecture/LayeringTest.php` |
| Domain layer stays framework-free | same |
| No `cache()`/`request()`/`encrypt()`/`decrypt()` in Domain (ADR-019) | `LayeringTest.php` |
| `declare(strict_types=1)` everywhere | `ConventionsTest.php` + Pint |
| No `dd()`/`dump()` survives | `ConventionsTest.php` |
| Repositories implement the contract | `ConventionsTest.php` |
| DTOs use the `DTO` suffix, in `Domain/DTOs/` (ADR-021) | `LayeringTest.php` |

Cross-module communication is **events only**. If module A needs something from
module B, B dispatches an event and A listens. See
[001_Architecture.md §14](001_Architecture.md).

---

## Checklist

- [ ] Service provider registered in `bootstrap/providers.php`
- [ ] Permissions registered in `PermissionRegistry`, `make permissions` run
- [ ] Repository bound to its contract
- [ ] Policy written; `owns()` overridden if seller-facing
- [ ] Role permissions attached in `RolePermissionSeeder`
- [ ] `$with` set on the repository for relations the module always needs
- [ ] Enum state machine unit-tested
- [ ] Filament resource registered on the right panel(s)
- [ ] `make check` passes
