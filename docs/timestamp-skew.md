# Timestamp skew — verification, prevention, and the backfill runbook

A sibling deployment was found storing lifecycle instants misinterpreted and
roughly three hours ahead of the truth. This document records whether Raftabul
shares that defect (it does not), what keeps it out, and the procedure for a
deployment where it is real.

## Why it is an outage, not a reporting defect

A shifted instant changes what the platform **does**:

- An `AwaitingPayment` order whose `placed_at` sits past the expiry boundary is
  never swept, so its reservation is held forever and the seller's offer quietly
  leaves the buy box (ADR-072).
- ADR-064's inferred delivery, return window and payout hold all key off
  `shipped_at` / `delivered_at`, so each one opens and closes at the wrong moment.
- A refund's eligibility is decided by a window the shift moves.

## Verification — Raftabul is clean (2026-10-03)

| Check | Result |
|---|---|
| OS / PHP / Laravel / database timezone | all four `Europe/Istanbul`, same second |
| Lifecycle columns (24 across 8 tables) | **every one `timestamptz`** |
| Independent clock: nginx access log vs `payments.paid_at` | `13:37:28 +0300` ↔ `13:37:28` — exact |
| `placed_at` vs `created_at` | 0–1 second apart |
| `awaiting_payment` past the window, unexpired | **0** (47 orders expired correctly) |
| `timestamp without time zone` columns | 17, all in imports/exports/loyalty/review-requests — none read by a window comparison |

**So there is nothing to repair here, and repairing it anyway would be the bug.**
The correction moves every instant in the money path by a full zone offset; applied
to correct data it shifts payment, delivery, return and payout by three hours. That
is why the tool below refuses to emit SQL for a database that measures clean.

## What keeps it out

`timestamptz` is the prevention, and it is load-bearing rather than stylistic: the
column stores the instant **with its offset**, so no amount of application
misconfiguration can make the database disagree about when something happened. A
bare `timestamp` stores a wall clock whose meaning depends on whoever reads it,
which is precisely how three hours goes missing.

`tests/Integration/TimestampSkewAuditOnPostgresTest` fails the build if a migration
reaches for `timestamp` on any lifecycle table. It lives in `tests/Integration`
because **SQLite cannot see any of this** — it has neither `AT TIME ZONE` nor a
`timestamptz` type, and would agree with a backwards expression just as readily.

## The tool

```bash
php artisan timestamps:audit                      # diagnose; writes nothing
php artisan timestamps:audit --snapshot=<path>     # capture the frontier, BEFORE the fix deploys
php artisan timestamps:audit --runbook=<path>      # emit SQL for review
```

It never writes to the database in any mode. `--runbook` prints statements for an
operator to read, back up against, and run in a maintenance window.

### Three decisions worth keeping

**The correction depends on the column type, and the two are opposites.** On a
`timestamptz`, `AT TIME ZONE 'UTC' AT TIME ZONE '<zone>'` moves the instant
**back** by the offset; on a bare `timestamp` the identical expression moves it
**forward**. One expression for both types corrects half the schema and doubles the
error on the rest — and the runbook looks equally plausible either way. The
expression is derived per column from `information_schema`, never typed by hand,
and `TimestampSkewAuditCommand::expression()` is public so the test can hand both
types to PostgreSQL and check which way the instant actually moved.

**The boundary is an id, not a date.** The premise is that stored times are wrong,
so `created_at < '<deploy time>'` cannot separate corrected writes from broken ones
— a three-hour error overlaps the cutoff in both directions. `id` is `bigserial`
and monotonic, so `max(id)` captured *before* the fix ships is an exact frontier.
`--runbook` refuses to run without a snapshot file for this reason.

**Never a fixed interval.** `AT TIME ZONE` resolves the offset in force on each
row's own date. Turkey abolished daylight saving in 2016, so every row this platform
has written is UTC+03 and `- interval '3 hours'` would happen to be right — but the
same expression is the repair for pre-2016 data or another zone, where January 2015
in Istanbul was +02 and July 2015 was +03. The test asserts those two corrections
differ, which a constant cannot satisfy.

## Procedure, where the skew is real

1. **Diagnose and keep the output.** `timestamps:audit` reports the clocks and the
   skew indicators — future-stamped rows, ordering violations, unexpired orders.
2. **Capture the frontier before deploying the code fix.**
   `timestamps:audit --snapshot=storage/app/frontier.json`. Out of order, the
   snapshot includes rows the fix already wrote correctly and the backfill shifts
   them wrong.
3. **Deploy the code fix**, so new writes are correct.
4. **Back up.** The owner takes the backup; this step is not optional and nothing
   below is reversible without it.
5. **Generate and read the SQL.** `timestamps:audit --runbook=storage/app/frontier.json`
   prints, per table, the frontier id, the affected row count, three real
   before→after pairs computed by the database using the very expression the
   `UPDATE` will use, and the full statements.
6. **Run it in a maintenance window.** One transaction wraps every table —
   per-table transactions would let a failure land with the orders corrected and
   their payments not, and a money path that disagrees with itself is worse than one
   uniformly three hours out.
7. **Verify.** Re-run `timestamps:audit`; the indicators should be empty.

### Running it twice is stopped by the database

The emitted SQL inserts a marker into `timestamp_backfills`, keyed by table name,
**in the same transaction as the update**. A second run collides on the primary key,
the transaction aborts, and nothing moves. A note in a runbook can be missed at 03:00;
a primary key cannot. The table is created by the runbook itself
(`CREATE TABLE IF NOT EXISTS`) rather than by a migration, so a deployment that never
needs the repair never carries the table.

## What this does not cover

- **Scope is the money and fulfilment path** — the eight tables in
  `TimestampSkewAuditCommand::SCOPE`. `imports`, `exports`, `loyalty_*` and
  `review_requests` carry times nothing branches on, and widening the blast radius of
  an `UPDATE` to buy tidiness is the wrong trade. Correct them by hand if a report
  ever needs it.
- **It cannot detect a uniform shift on its own.** The checks find instants in the
  future, pairs in the wrong order, and orders that should have expired — all of
  which a *partial* or *forward* shift produces. A whole database shifted
  consistently backwards leaves every one of them intact, and proving that needs an
  independent clock: a web-server access log, a gateway's own records, a receipt.
  That is how Raftabul was cleared, and the comparison is not something the command
  can make for you.
