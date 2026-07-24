# tests/Modules

Per-module test suites. **Empty until Sprint 1.**

```
Store/
  Unit/       state machines, DTOs — no database
  Feature/    actions, policies, endpoints
```

Already wired: the `Modules` suite is declared in `phpunit.xml`, and
`tests/Pest.php` gives everything under it `RefreshDatabase`.

```bash
make test-feature
./vendor/bin/pest --testsuite=Modules
```

Kept separate from `tests/Feature/` so a module's tests travel with the module,
and so `--testsuite=Modules` can be run alone.

See [docs/testing.md](../../docs/testing.md) and
[docs/modules.md](../../docs/modules.md).
