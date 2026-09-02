---
description: Pre-merge readiness gate — runs make check + storefront tsc + a debug/strict-types audit, fail-fast.
argument-hint: "[quick | full | pre-commit | pre-pr]  (default: full)"
---

Run a readiness verification for MarketplaceOS. Mode = `$ARGUMENTS` (default `full`).
Run the stages **in order and stop at the first critical failure** (fail-fast). Report a
clear PASS / FAIL verdict at the end with the failing stage named.

## Stages

1. **Debug-statement audit (non-negotiable #8) — always.**
   - Backend: search `app/` for `dd(`, `dump(`, `var_dump(`, `die(`, `exit(` (exclude
     `vendor/`). ANY hit = FAIL.
   - Storefront: search `storefront/src/` for `console.log(` and `debugger`. Flag hits.
2. **strict_types (non-negotiable #1) — always.** Every changed/new `.php` file must start
   with `declare(strict_types=1);`. A missing one = FAIL.
3. **Storefront typecheck — all modes except when there are no storefront changes.**
   `cd storefront && npx tsc --noEmit`. Non-zero = FAIL. Run `npm run lint` too if a lint
   script exists.
4. **Backend `make check` — full / pre-pr only** (lint + static analysis + tests, exactly
   what CI runs, in Docker). If Docker isn't reachable in this session, say so explicitly
   and hand this stage to the server session rather than skipping it silently.
5. **Git hygiene — pre-commit / pre-pr.** No stray debug files, no committed `.env`, no
   `nul`/scratch artifacts staged.
6. **Security screen — pre-pr only.** If the diff touches money, ledger, auth, refunds,
   PayTR, or a `/api/v1` endpoint, invoke the `security-reviewer` agent on the diff and
   fold its critical/high findings into the verdict.

## Modes

- `quick` — stages 1–3 only (fast local sanity).
- `pre-commit` — stages 1, 2, 3, 5 scoped to changed files.
- `full` (default) — stages 1–4.
- `pre-pr` — all stages 1–6.

Do not "fix" anything here — report. If everything passes, say so and name what ran.
