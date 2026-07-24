# Testing

Pest 3. Four suites, each with a different contract.

```bash
make test          # everything
make test-unit
make test-feature
make test-arch
make coverage      # fails under 70%
```

---

## Suite layout

| Suite | Database | What belongs there |
|---|---|---|
| `Unit` | **none** | Enums, DTOs, value objects, pure functions |
| `Feature` | yes | Endpoints, policies, actions, services |
| `Architecture` | none | Layering and convention rules |
| `Modules` | yes | Per-module suites (Sprint 1+) |

The Unit suite deliberately does **not** get `RefreshDatabase`
(`tests/Pest.php`). A "unit" test that touches the database is an integration
test wearing the wrong label, and the missing trait makes that mistake fail
loudly instead of merely being slow.

---

## Database strategy

The suite runs on **in-memory SQLite** by default (`.env.testing`). Hermetic,
parallel-safe, fast.

But SQLite is not PostgreSQL. It silently accepts things Postgres rejects, and
lacks JSONB operators and partial indexes entirely. So CI runs the Feature suite
a **second time against real PostgreSQL** (see `.github/workflows/ci.yml`).

Anything depending on Postgres-specific behaviour — JSONB queries, the partial
unique index on `users`, full-text search — must be verified there. The users
migration is explicit about this: it creates the partial index only on `pgsql`.

---

## No network, ever

`TestCase::setUp()` calls `Http::preventStrayRequests()`.

Without it a forgotten HTTP call makes the suite slow, flaky and dependent on
someone else's uptime. Concretely: `StrongPassword` checks Have I Been Pwned,
so every single user factory would hit the network.

---

## Helpers

```php
$admin    = $this->actingAsAdmin();     // signs in on the 'admin' guard
$seller   = $this->actingAsSeller();
$customer = $this->actingAsCustomer();

$this->seedRolesAndPermissions();       // only where authorisation is asserted

// Grant a permission directly, bypassing roles — tests the policy in
// isolation from whichever role happens to bundle it today.
$this->grant($seller, 'store.update');
```

Roles are **not** seeded globally. Most tests do not need them, and seeding for
every test costs more than it saves.

---

## Factories

One per actor type, because each is a distinct model with its own global scope:

```php
Admin::factory()->superAdmin()->create();
Seller::factory()->employee()->create();
Customer::factory()->unverified()->create();
Admin::factory()->withTwoFactor()->suspended()->create();
```

`Admin::factory()` must build an `Admin`, not a `User` with an admin `type` —
otherwise the global scope is bypassed and the test proves nothing.

All factory users share one password hash (`UserFactory::PASSWORD` = `password`)
because hashing dominates factory runtime.

---

## Custom expectations

```php
expect($value)->toBeEnumOf(Status::class);

expect(fn () => $repository->paginate())->toRunQueries(3);
```

`toRunQueries()` matters even with strict mode on: a count creeping from 3 to 30
without ever lazy-loading is still a regression, and only an explicit budget
catches it.

---

## Architecture tests

These are not decoration — they are the enforcement mechanism for
[001_Architecture.md](001_Architecture.md). Documentation describing a layering rule is
a suggestion; a failing build is a rule.

`tests/Architecture/LayeringTest.php` fails if:
- the Domain layer imports Eloquent, `Request` or the `DB` facade
- Domain depends on Infrastructure or Presentation
- Application depends on Presentation
- a module reaches into another module

`tests/Architecture/ConventionsTest.php` fails if:
- any file is missing `declare(strict_types=1)`
- `dd`, `dump`, `ray`, `var_dump`, `die` or `exit` appear anywhere
- a base class is not abstract, a contract is not an interface
- a repository does not implement `RepositoryContract`

Plus Pest's `laravel` and `security` presets.

**If one starts failing, the question is not "how do I silence it" but "did we
mean to change that decision".**

---

## Guard isolation

`tests/Feature/Auth/GuardIsolationTest.php` deserves special mention. It asserts
that the three guards cannot resolve each other's users.

A failure there is a **privilege-escalation bug**, not a test failure. Do not
skip it, do not adjust it to pass.

---

## Coverage

70% minimum, enforced in CI. A floor, not a target — architecture tests and the
guard isolation suite carry more weight than the percentage does.
