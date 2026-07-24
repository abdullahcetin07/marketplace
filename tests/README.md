# tests

Pest 3. Full guide: [docs/testing.md](../docs/testing.md).

| Directory | Database | Contains |
|---|---|---|
| `Unit/` | **no** | Enums, DTOs, pure logic |
| `Feature/` | yes | Endpoints, policies, actions, services |
| `Architecture/` | no | Layering and convention rules |
| `Modules/` | yes | Per-module suites (empty until Sprint 1) |

The Unit suite deliberately has no `RefreshDatabase`. A "unit" test that touches
the database is an integration test wearing the wrong label — the missing trait
makes that fail loudly rather than merely being slow.

```bash
make test        make test-unit    make test-feature
make test-arch   make coverage
```

---

## Two files that matter more than the rest

**`Architecture/`** — these are the enforcement mechanism for
[docs/001_Architecture.md](../docs/001_Architecture.md). If one starts failing, the
question is *"did we mean to change that decision"*, not *"how do I silence
it"*.

**`Feature/Auth/GuardIsolationTest.php`** — asserts the three guards cannot
resolve each other's users. A failure there is a **privilege-escalation bug**.
Do not skip it, do not adjust it to pass.

---

## Helpers on `TestCase`

```php
$this->actingAsAdmin();  $this->actingAsSeller();  $this->actingAsCustomer();
$this->seedRolesAndPermissions();
$this->grant($user, 'store.update');   // direct permission, bypassing roles
```

`Http::preventStrayRequests()` is on globally — no test may reach the network.
Without it, every user factory would call Have I Been Pwned via the password
rule.
