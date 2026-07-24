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
