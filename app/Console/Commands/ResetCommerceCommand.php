<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Wipe the TEST catalog and commerce data; keep accounts, stores and config
 * (owner-approved 2026-08-08).
 *
 * **THE MOST DESTRUCTIVE THING IN THIS CODEBASE, AND IT IS BUILT TO BE HARD TO
 * FIRE BY ACCIDENT.** The owner is about to enter their real categories, brands
 * and products, so the factory-built test data goes — but a merchant who
 * registered, opened a store and passed KYC must not have to do it again. Every
 * design choice here follows from those two sentences.
 *
 * **RUN ONCE, BY HAND, NEVER ON A SCHEDULE.** It is not registered in
 * `routes/console.php` and must not be: there is no state in which "wipe the
 * catalogue" is the right answer to a clock.
 *
 * **IT TRUNCATES BY LIST, NOT BY CASCADE.** The catalogue is uuid-linked with no
 * foreign key to offers or inventory (ADR-040), so deleting `products` cascades
 * nowhere and each group has to be named. `TRUNCATE ... CASCADE` was the shorter
 * spelling and is the wrong one: cascade follows real FKs wherever they go, and
 * some of them point at tables in the KEEP list. `session_replication_role` is
 * the explicit version — FK triggers suspended for this session only, restored in
 * a `finally` so a failure halfway cannot leave the connection unguarded.
 *
 * **MEDIA FILES GO BEFORE THE ROWS**, because the rows are the only record of
 * where the files are. And **the `media` table is never truncated**: store
 * branding logos and organization KYC documents live in it and are KEPT. Only
 * rows whose `model_type` is a model being deleted are touched.
 *
 * **THE AUDIT TRAIL SURVIVES.** `audit_entries` and `activity_entries` are
 * append-only by design (CLAUDE.md non-negotiable #9) and truncating them would
 * destroy the record of everything that happened before the reset — including
 * this reset.
 *
 * @see BUILD_RESET_COMMERCE.md
 */
final class ResetCommerceCommand extends Command
{
    /**
     * Media belonging to these is deleted, files first.
     *
     * **`Brand` IS ON THIS LIST AND THE WORK ORDER DID NOT NAME IT.** Brands are
     * truncated, brands carry a logo, and a media row pointing at a `brands` id
     * that no longer exists is a file nothing will ever clean up. `Category` has
     * no media collection today and is left off deliberately rather than
     * defensively — a name here that is not a media-bearing model reads as though
     * it were.
     *
     * NOT `OrganizationDocument`, NOT `StoreBranding`, NOT `StoreOpeningRequest`:
     * those belong to the accounts and stores that survive, and they are the
     * reason the `media` table is never truncated wholesale.
     *
     * **LITERAL STRINGS, NOT `::class`, AND THAT IS THE LAYERING RULE RATHER THAN
     * A STYLE CHOICE.** `app/Console` may not reference a module's models —
     * `LayeringTest` fails the build on it, and it caught the first version of
     * this list. Nothing is lost by writing them out: `media.model_type` IS a
     * string column, so these are the stored values rather than a reference that
     * happens to resolve to them.
     *
     * WHAT IS LOST is rename-safety, so `ResetCommerceCommandTest` asserts every
     * name here still resolves to a real class — a renamed model fails a test
     * instead of silently orphaning its files.
     *
     * @var array<int, string>
     */
    public const MEDIA_OF = [
        'App\\Modules\\Catalog\\Domain\\Models\\Product',
        'App\\Modules\\Catalog\\Domain\\Models\\ProductVariant',
        'App\\Modules\\Catalog\\Domain\\Models\\Brand',
        'App\\Modules\\Reviews\\Domain\\Models\\Review',
    ];

    /**
     * Media that must SURVIVE — asserted against, never deleted.
     *
     * A list of what to keep is not needed by the code, which deletes by
     * `MEDIA_OF` alone. It is here because the test needs something to check the
     * dangerous direction against, and a name that only exists in a test is a
     * name somebody deletes.
     *
     * @var array<int, string>
     */
    public const MEDIA_KEPT = [
        'App\\Modules\\Organization\\Domain\\Models\\OrganizationDocument',
        'App\\Modules\\Organization\\Domain\\Models\\StoreOpeningRequest',
        'App\\Modules\\Store\\Domain\\Models\\StoreBranding',
    ];

    /**
     * Truncated, in this order.
     *
     * **CHILDREN BEFORE PARENTS**, even though FK triggers are suspended: the
     * order is what a reader checks this list against, and one that reads
     * top-down is one somebody can verify. It is also what would still be correct
     * if the truncation strategy ever changed.
     *
     * @var array<int, string>
     */
    private const DELETE = [
        // Payment — the deepest children first.
        'payment_refund_lines',
        'payment_refunds',
        'seller_ledger_entries',
        'settlement_windows',
        'payouts',
        'payments',

        // Shipping, Reviews, Questions — leaves that point at orders/products.
        'shipments',
        'reviews',
        'questions',

        // Order.
        'return_requests',
        'cancellation_requests',
        'order_lines',
        'orders',
        'cart_items',
        'carts',

        // Inventory — the ledger before the pools it belongs to.
        'stock_reservations',
        'stock_movements',
        'stock_items',

        // Offer.
        'offers',

        // Catalog — pivots, then the entities, then the slug registry.
        'product_attribute_value',
        'variant_attribute_value',
        'category_attribute',
        'product_variants',
        'products',
        'attribute_values',
        'attributes',
        'categories',
        'brands',
        'slugs',
    ];

    /**
     * Tables whose foreign key points back at themselves, and the column that
     * does it. @see the note in `truncate()`.
     *
     * @var array<string, string>
     */
    private const SELF_REFERENCING = [
        'categories' => 'parent_id',
    ];

    protected $signature = 'marketplace:reset-commerce
                            {--force : Skip the confirmation prompt (and the production guard)}
                            {--include-addresses : Also wipe saved customer addresses}
                            {--dry-run : Report what would be deleted without writing}';

    protected $description = 'Delete test catalog + commerce data, keeping accounts, stores and config';

    public function handle(): int
    {
        $tables = $this->tables();
        $counts = $this->countRows($tables);
        $mediaRows = $this->mediaQuery()->count();

        $this->report($tables, $counts, $mediaRows);

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->warn('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        if (! $this->confirmed()) {
            $this->warn('Aborted. Nothing was deleted.');

            return self::FAILURE;
        }

        $files = $this->deleteMedia();
        $this->truncate($tables);

        $rows = array_sum($counts);

        /*
        | **THE RESET IS ITSELF AUDITED**, in the trail it deliberately did not
        | truncate. Somebody looking at an empty catalogue six months from now
        | needs a line saying a human did this on purpose.
        */
        Log::channel('audit')->warning('Commerce data reset', [
            'tables' => count($tables),
            'rows' => $rows,
            'media_files' => $files,
            'include_addresses' => (bool) $this->option('include-addresses'),
            'environment' => app()->environment(),
        ]);

        $this->newLine();
        $this->info("Done. {$rows} rows across ".count($tables)." tables; {$files} media directories removed.");
        $this->line('Accounts, stores, roles, config lookups and the audit trail were not touched.');
        $this->newLine();
        $this->comment('Next: php artisan optimize:clear');

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function tables(): array
    {
        $tables = self::DELETE;

        if ($this->option('include-addresses')) {
            /*
            | OPT-IN, because it belongs to a customer who is NOT being deleted.
            | An order snapshots both addresses onto itself (ADR-056), so nothing
            | dangles either way — this is about whether a surviving shopper finds
            | their address book empty next time they check out.
            */
            $tables[] = 'customer_addresses';
        }

        // A table named here but absent from this database is a typo, and a
        // TRUNCATE of it would abort the whole run halfway through.
        return array_values(array_filter($tables, fn (string $t): bool => \Illuminate\Support\Facades\Schema::hasTable($t)));
    }

    /**
     * @param array<int, string> $tables
     *
     * @return array<string, int>
     */
    private function countRows(array $tables): array
    {
        $counts = [];

        foreach ($tables as $table) {
            // BEFORE the truncate, or there is nothing left to report.
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * @param array<int, string> $tables
     * @param array<string, int> $counts
     */
    private function report(array $tables, array $counts, int $mediaRows): void
    {
        $this->newLine();
        $this->line('<fg=red;options=bold>DELETE</> — test catalog + commerce:');

        foreach ($tables as $table) {
            $count = $counts[$table];
            $colour = $count > 0 ? 'yellow' : 'gray';
            $this->line(sprintf('  <fg=%s>%-28s %8d</>', $colour, $table, $count));
        }

        $this->line(sprintf('  <fg=yellow>%-28s %8d</>', 'media (rows, files too)', $mediaRows));

        $this->newLine();
        $this->line('<fg=green;options=bold>KEEP</> — untouched:');
        $this->line('  accounts, sessions, roles & permissions');
        $this->line('  organizations, stores, KYC, bank accounts');
        $this->line('  tax_rates, commission_rules, cargo_companies, currencies, geo, settings');
        $this->line('  audit_entries, activity_entries (append-only evidence)');
        $this->line('  media belonging to stores and organizations');

        if (! $this->option('include-addresses')) {
            $this->line('  customer_addresses (pass --include-addresses to wipe these too)');
        }

        $this->newLine();
        $this->line('Database: <fg=cyan>'.DB::connection()->getDatabaseName().'</> · environment: <fg=cyan>'.app()->environment().'</>');
    }

    /**
     * **TWO GATES, AND `--force` OPENS BOTH — which is the point of naming it
     * that.** A prompt stops the hand that typed the command by mistake; the
     * production guard stops the one that typed it on the wrong server. Neither
     * is a substitute for the other, and an operator who means it says so once.
     */
    private function confirmed(): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (app()->environment('production')) {
            $this->newLine();
            $this->error('Refusing to run in production without --force.');

            return false;
        }

        $this->newLine();

        return $this->confirm(
            'TÜM test katalog + ticaret verisi silinecek (ürün/sipariş/ödeme/…), '
            .'hesaplar + mağazalar + config KALACAK. Devam?',
            false,
        );
    }

    /**
     * Media rows belonging to the models being deleted. @see `MEDIA_OF`.
     */
    private function mediaQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('media')->whereIn('model_type', self::MEDIA_OF);
    }

    /**
     * Delete the FILES, then the rows — in that order, and never the table.
     *
     * **THE ROWS ARE THE ONLY MAP TO THE FILES.** Truncate first and every image
     * of every deleted product stays on disk (or in the bucket, being paid for)
     * with nothing left to find it by.
     *
     * THE DISK COMES FROM THE ROW, not from config: it varies per item (`s3` for
     * one, local `public` for another), and reading a default here would delete
     * from the wrong place, or from nowhere, silently.
     *
     * A FAILING DELETE IS LOGGED, NOT FATAL. A file already gone, a bucket
     * briefly unreachable — neither is a reason to abandon a reset halfway and
     * leave the database in a state nobody planned.
     */
    private function deleteMedia(): int
    {
        $removed = 0;

        foreach ($this->mediaQuery()->get(['id', 'disk', 'conversions_disk']) as $row) {
            /** @var object{id: int, disk: string, conversions_disk: string|null} $row */
            $disks = array_unique(array_filter([$row->disk, $row->conversions_disk]));

            foreach ($disks as $disk) {
                try {
                    // Spatie stores each item under a directory named by its id.
                    Storage::disk((string) $disk)->deleteDirectory((string) $row->id);
                    $removed++;
                } catch (Throwable $exception) {
                    Log::channel('errors')->warning('Could not delete media during a commerce reset', [
                        'media_id' => $row->id,
                        'disk' => $disk,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }
        }

        // The rows, explicitly — NOT `TRUNCATE media`, which would take store
        // branding and organization documents with it.
        $this->mediaQuery()->delete();

        return $removed;
    }

    /**
     * @param array<int, string> $tables
     */
    private function truncate(array $tables): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';

        if ($pgsql) {
            // FK triggers off for THIS SESSION only. Not a schema change, not
            // visible to anybody else's connection.
            DB::statement('SET session_replication_role = replica');
        }

        try {
            foreach ($tables as $table) {
                $this->line("  truncating <fg=yellow>{$table}</>");

                if ($pgsql) {
                    // RESTART IDENTITY so a fresh catalogue starts at id 1 rather
                    // than continuing a sequence only the deleted data explains.
                    DB::statement("TRUNCATE TABLE {$table} RESTART IDENTITY");

                    continue;
                }

                /*
                | THE FALLBACK PATH — SQLite, which is what the suite runs on.
                | `TRUNCATE` does not exist there, and `PRAGMA foreign_keys = OFF`
                | is a NO-OP INSIDE A TRANSACTION, which is exactly where every
                | test executes (`RefreshDatabase`). So the deletes have to
                | satisfy the constraints rather than suspend them — which the
                | child-before-parent ordering of `DELETE` already does, with one
                | exception.
                */
                if (isset(self::SELF_REFERENCING[$table])) {
                    /*
                    | **A TABLE THAT POINTS AT ITSELF CANNOT BE EMPTIED IN ONE
                    | STATEMENT** — `categories.parent_id` is the only one here,
                    | and a single `DELETE` trips its own foreign key on whichever
                    | row the engine happens to reach first. Nulling the link
                    | turns a tree into a flat set, and a flat set deletes.
                    */
                    DB::table($table)->update([self::SELF_REFERENCING[$table] => null]);
                }

                DB::table($table)->delete();
            }
        } finally {
            /*
            | **RESTORED IN `finally`, ALWAYS.** A failure between the two
            | statements would otherwise leave this connection able to write past
            | every foreign key in the database — and connections are pooled.
            */
            if ($pgsql) {
                DB::statement('SET session_replication_role = origin');
            }
        }
    }
}
