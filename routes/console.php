<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
|
| Everything here is housekeeping that keeps the platform from degrading over
| time. Each entry is onOneServer() so a multi-container deployment does not
| run the same cleanup several times concurrently.
|
*/

/*
| Audit and activity retention.
|
| These two tables grow faster than anything else in the schema — every field
| change on every record lands in one of them. They are pruned SEPARATELY
| because they answer different questions and carry different legal weight:
| audit is dispute evidence (730 days), activity is operational history
| (365 days). @see docs/audit.md
|
| Query-builder deletes, not model deletes: the models refuse deletion by
| design (append-only), and per-row events on millions of rows would be
| pointless.
*/
Schedule::call(function (): void {
    $days = (int) config('marketplace.security.retention.audit_days', 730);

    DB::table('audit_entries')
        ->where('created_at', '<', now()->subDays($days))
        ->delete();
})->name('prune-audit-entries')->dailyAt('03:00')->onOneServer();

Schedule::call(function (): void {
    $days = (int) config('marketplace.security.retention.activity_days', 365);

    DB::table('activity_entries')
        ->where('created_at', '<', now()->subDays($days))
        ->delete();
})->name('prune-activity-entries')->dailyAt('03:15')->onOneServer();

/*
| Login attempts are the shortest-lived of the three: they exist for attack
| detection, which operates on hours, not years.
*/
Schedule::call(function (): void {
    $days = (int) config('marketplace.security.retention.login_attempt_days', 180);

    DB::table('login_attempts')
        ->where('created_at', '<', now()->subDays($days))
        ->delete();
})->name('prune-login-attempts')->dailyAt('03:30')->onOneServer();

/*
| Revoked and long-idle session rows. @see SessionService::prune()
*/
Schedule::call(function (): void {
    app(App\Modules\Identity\Application\Services\SessionService::class)->prune();
})->name('prune-user-sessions')->dailyAt('03:45')->onOneServer();

/*
| Expired Sanctum tokens are dead weight and a (small) attack surface.
*/
Schedule::command('sanctum:prune-expired --hours=24')
    ->daily()
    ->onOneServer();

/*
| DELIVERY INFERENCE (ADR-064). Most buyers never press "Teslim aldım" — they
| have the goods and no reason to tell anyone — so without this sweep payout
| would wait on a confirmation that never comes and a seller would never be paid
| for a parcel that arrived weeks ago.
|
| HOURLY, not daily, and the reason is the buyer rather than the seller: the
| window is measured in days, so a daily run would be accurate enough for payout
| — but delivery also opens the RETURN window, and a buyer who has to wait until
| 04:00 tomorrow to be told their parcel "arrived" has lost hours of it. Hourly
| costs one cheap indexed query.
|
| It is idempotent and bounded per run, so running it often is free.
*/
Schedule::job(new App\Modules\Shipping\Application\Jobs\SweepTransitDeliveriesJob)
    ->name('sweep-transit-deliveries')
    ->hourly()
    ->onOneServer();

/*
| AUTOMATIC PAYOUTS (owner decision, 2026-08-06). One pending payout per seller
| for everything whose delivery hold has expired — the DECISION is automated, the
| bank is not: a human still makes the transfer and marks it paid.
|
| DAILY, AND AT AN HOUR A FINANCE TEAM STARTS RATHER THAN ONE THE SERVER LIKES.
| The batch is what somebody works through in the morning, so it should be waiting
| for them rather than landing halfway through their day. Nothing about the
| arithmetic cares when it runs — a payout that waits an extra night is a payout
| that waits an extra night — but the person reading it does.
|
| Idempotent: a seller with a pending payout is skipped, and creating one debits
| the balance so the next run finds nothing.
*/
Schedule::job(new App\Modules\Payment\Application\Jobs\CreateDuePayoutsJob)
    ->name('create-due-payouts')
    ->dailyAt('07:00')
    ->onOneServer();

/*
| Horizon retains metrics snapshots indefinitely otherwise.
*/
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->onOneServer();

/*
| Clear failed jobs older than a week — they are in the errors log and the
| audit trail by then, and a growing failed_jobs table slows the dashboard.
*/
Schedule::command('queue:prune-failed --hours=168')
    ->daily()
    ->onOneServer();

Schedule::command('queue:prune-batches --hours=48')
    ->daily()
    ->onOneServer();

/*
| Telescope-style local noise: prune Debugbar's stored requests.
*/
Schedule::command('debugbar:clear')
    ->daily()
    ->environments(['local']);

/*
|--------------------------------------------------------------------------
| The payment window (ADR-072) — the sweep that keeps sellers sellable
|--------------------------------------------------------------------------
|
| **THIS ONE IS NOT HOUSEKEEPING.** ADR-057 made placement HOLD a reservation and
| Payment commit it, and until now nothing released the hold when a customer
| closed the tab at the card form. The hold sat forever, a seller's `available`
| fell toward zero, and their offer dropped off the buy box WHILE STILL DECLARING
| STOCK — every abandoned checkout permanently costing that seller inventory.
|
| EVERY MINUTE, because the window is five: a sweep slower than the deadline it
| enforces lets an abandoned hold outlive its own expiry by the sweep interval.
| `onOneServer()` like the two above — one process across the fleet, or two
| runners race to expire the same order.
|
| Its first run SELF-HEALS the backlog: everything stuck in `AwaitingPayment` from
| before this shipped is past the window by definition.
*/
Schedule::job(new App\Modules\Order\Application\Jobs\ExpireAwaitingPaymentJob)
    ->name('expire-awaiting-payment')
    ->everyMinute()
    ->onOneServer();

/*
| AND THE OTHER HALF OF THE SAME LEAK, which had been written and never
| scheduled. `ExpireReservationsJob` sweeps the PRE-placement window — a basket
| abandoned at the address step, 30 minutes, ending in `Cancelled` — and has
| existed since Order shipped without ever running. Two windows, two outcomes,
| two jobs, both on the schedule now.
|
| A job nobody scheduled is indistinguishable from a job with nothing to do,
| which is precisely how this went unnoticed.
*/
Schedule::job(new App\Modules\Order\Application\Jobs\ExpireReservationsJob)
    ->name('expire-pending-reservations')
    ->everyMinute()
    ->onOneServer();

/*
| THE SELLABLE FLAG SELF-HEALS (ADR-079). `products.is_sellable` is a cache of
| what Offer, Store and Inventory say, kept current by listeners — but
| sellability also changes for reasons nothing announces to Catalog: a store
| going dark, a reservation ageing out, a row written by a fix script.
|
| **A DENORMALISED FACT WITH NO REBUILD IS A FACT THAT QUIETLY DIVERGES**, and
| the failure is silent in the worst direction — a product that is right in the
| table and invisible to every buyer. Ten minutes is often enough that drift is
| never visible for long, and rare enough that a full recompute costs nothing.
*/
Schedule::command('catalog:refresh-sellability')
    ->name('refresh-product-sellability')
    ->everyTenMinutes()
    ->onOneServer()
    ->withoutOverlapping();
