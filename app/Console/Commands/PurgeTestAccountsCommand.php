<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-deletes customer accounts registered on domains that cannot receive
 * mail, and only those with nothing behind them.
 *
 * **Why this exists.** Ninety-seven accounts on `@example.com`, `@test.com` and
 * friends accumulated during storefront testing. Each one is a trigger for the
 * failure `BlockedRecipientGuard` now contains: a notification to a reserved
 * domain that Resend rejects, which took the failover chain down for everyone
 * else's mail. The guard is the permanent defence; this removes the accounts
 * that keep pulling the trigger.
 *
 * **It reads the same list as the guard** (`mail.blocked_recipient_domains`),
 * so "not a real address" has one definition on this platform rather than two
 * that drift. The consequence is worth stating: adding a domain to that config
 * makes this command consider its accounts disposable.
 *
 * **An account with history is never touched, however fake its address looks.**
 * An order, a payment, a points entry, a review, a question or a return means
 * somebody did something that other records point at, and a soft-deleted user
 * behind a real order is a support call, not a tidy database. Carts, saved
 * addresses and review invitations do NOT count as history: they belong to the
 * account and say nothing about anyone else's data.
 *
 * **Soft delete, not delete.** `users` is soft-deleting by design and the rows
 * here are cheap to keep; a hard delete would be the one irreversible step in a
 * cleanup whose whole justification is that these accounts do not matter.
 *
 * Reports by default. `--apply` is the only thing that writes.
 */
final class PurgeTestAccountsCommand extends Command
{
    /**
     * Tables that mean "this account has history", and the column naming the
     * customer in each.
     *
     * `carts`, `customer_addresses` and `review_requests` are deliberately
     * absent: an abandoned basket, a saved address and an invitation nobody
     * answered are all facts about the account itself, not about the platform's
     * money or its public content.
     *
     * @var array<string, string>
     */
    private const HISTORY = [
        'orders' => 'customer_uuid',
        'payments' => 'customer_uuid',
        'loyalty_ledger' => 'customer_uuid',
        'loyalty_holds' => 'customer_uuid',
        'reviews' => 'customer_uuid',
        'questions' => 'customer_uuid',
        'return_requests' => 'customer_id',
    ];

    protected $signature = 'users:purge-test-accounts
                            {--apply : Soft-delete the listed accounts (default is report only)}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Soft-delete customer accounts on undeliverable test domains that have no history';

    public function handle(): int
    {
        $domains = $this->domains();

        if ($domains === []) {
            $this->warn('mail.blocked_recipient_domains is empty — nothing defines a test account.');

            return self::SUCCESS;
        }

        $this->line('Test domains: '.implode(', ', $domains));

        $candidates = $this->candidates($domains);

        if ($candidates->isEmpty()) {
            $this->info('No test accounts to purge.');

            return self::SUCCESS;
        }

        [$purgeable, $withHistory] = $candidates->partition(
            fn (Customer $customer): bool => ! $this->hasHistory($customer),
        );

        $this->report($purgeable->all(), $withHistory->all());

        if ($purgeable->isEmpty()) {
            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('Report only — nothing was written. Re-run with --apply to soft-delete.');

            return self::SUCCESS;
        }

        if (! $this->confirmed($purgeable->count())) {
            $this->warn('Aborted. Nothing was deleted.');

            return self::FAILURE;
        }

        $deleted = $this->purge($purgeable->all());

        $this->newLine();
        $this->info("Soft-deleted {$deleted} test account(s).");

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function domains(): array
    {
        /** @var array<int, string> $domains */
        $domains = (array) config('mail.blocked_recipient_domains', []);

        return array_values(array_filter(array_map(
            static fn (mixed $domain): string => mb_strtolower(trim((string) $domain)),
            $domains,
        )));
    }

    /**
     * Live customers whose address sits on one of the configured domains.
     *
     * `@` is part of the pattern so `notexample.com` does not match
     * `example.com`, and the underscore escape matters more than it looks: in
     * SQL `_` is a single-character wildcard, so an unescaped `test_.com` would
     * match `testX.com`.
     *
     * @param array<int, string> $domains
     *
     * @return \Illuminate\Support\Collection<int, Customer>
     */
    private function candidates(array $domains)
    {
        return Customer::query()
            ->where(function ($query) use ($domains): void {
                foreach ($domains as $domain) {
                    $query->orWhere('email', 'like', '%@'.addcslashes($domain, '%_'));
                }
            })
            ->orderBy('id')
            ->get();
    }

    private function hasHistory(Customer $customer): bool
    {
        foreach (self::HISTORY as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $value = $column === 'customer_id' ? $customer->getKey() : $customer->uuid;

            if (DB::table($table)->where($column, $value)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, Customer> $purgeable
     * @param array<int, Customer> $withHistory
     */
    private function report(array $purgeable, array $withHistory): void
    {
        $this->newLine();
        $this->table(
            ['id', 'uuid', 'email', 'registered', 'verified'],
            array_map(static fn (Customer $customer): array => [
                $customer->getKey(),
                $customer->uuid,
                $customer->email,
                (string) $customer->created_at,
                $customer->email_verified_at === null ? 'no' : 'yes',
            ], $purgeable),
        );

        $this->line('Purgeable: '.count($purgeable));

        if ($withHistory !== []) {
            /*
            | Named, not just counted. "Three accounts were kept" invites the
            | question this line answers, and the addresses are on domains that
            | belong to nobody.
            */
            $this->warn('Kept (has orders, payments, points, reviews, questions or returns): '.count($withHistory));

            foreach ($withHistory as $customer) {
                $this->line('  - '.$customer->email);
            }
        }
    }

    private function confirmed(int $count): bool
    {
        if ($this->option('force')) {
            return true;
        }

        return $this->confirm("Soft-delete {$count} test account(s)?", false);
    }

    /**
     * @param array<int, Customer> $purgeable
     */
    private function purge(array $purgeable): int
    {
        $deleted = 0;

        foreach ($purgeable as $customer) {
            /*
            | Through the model, one at a time, rather than one mass update: the
            | soft delete fires the observers that keep search and the audit
            | trail honest, and this runs once against a hundred rows.
            */
            $customer->delete();
            $deleted++;
        }

        Log::info('Test accounts purged', [
            'count' => $deleted,
            'emails' => array_map(static fn (Customer $customer): string => (string) $customer->email, $purgeable),
        ]);

        return $deleted;
    }
}
