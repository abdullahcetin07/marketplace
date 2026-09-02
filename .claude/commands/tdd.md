---
description: Drive test-first development (Red→Green→Refactor) via the tdd-guide agent — Pest, verify-fail-first, 100% on money/auth.
argument-hint: "[feature or bug to build test-first]"
---

Invoke the **tdd-guide** agent to build `$ARGUMENTS` test-first for MarketplaceOS.

Hold the agent to the loop: define the interface → write the failing test → **verify it
fails for the right reason** → minimal implementation → verify pass → refactor.

Carry these MarketplaceOS rules:
- Unit tests have **no database** — a test that needs one is a Feature test;
  locale-touching Feature tests call `seedPlatform()`.
- Use **≥2 rows** in fixtures to actually catch strict-mode lazy loads.
- **100% coverage on money/auth paths** (commission, KDV, refunds via `RefundableLines`,
  seller/loyalty ledgers, reservations, payout, policies, PayTR callback); 80%+ elsewhere,
  covering happy/error/edge/boundary.
- Never edit `LayeringTest` / `CatalogBoundaryTest` / `GuardIsolationTest` to pass.
- Run in Docker (`make test` / `make check`); storefront via `tsc --noEmit` + its runner.
