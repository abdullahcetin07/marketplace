---
description: Generate/run Playwright E2E tests for the storefront via the e2e-runner agent (sandbox only).
argument-hint: "[flow]  e.g. checkout, cart, search, points  (default: full checkout journey)"
---

Invoke the **e2e-runner** agent to cover the MarketplaceOS storefront flow: `$ARGUMENTS`
(default: the full browse → product → cart → checkout → PayTR-sandbox → order-result →
account journey).

Reminders to carry into the agent:
- **Storefront only** (`storefront/`), never `app/**`.
- **PayTR / payments = SANDBOX or staging only.** Never a live card, never production
  checkout, never `marketplace:reset-commerce` on prod.
- Prioritize by money risk; select by `data-testid`; wait for API responses, not fixed
  timeouts; cover mobile (375) and desktop (1280); quarantine `@flaky` rather than delete.
- `tsc --noEmit` must stay green after any test/`data-testid` edits.

Report what passed, what's flaky and why, and remaining coverage gaps.
