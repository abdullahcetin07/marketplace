<?php

declare(strict_types=1);

namespace Database\Modules\Catalog\Seeders;

use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\TaxRate;
use Illuminate\Database\Seeder;

/**
 * The Turkish KDV brackets, and the backfill that makes the nullable column safe
 * (ADR-056, Catalog.md §2.4).
 *
 * REGISTERED IN `DatabaseSeeder`, unlike `CatalogTaxonomySeeder` — and the
 * difference is worth stating. A taxonomy is an operator's editorial choice, so
 * the platform can ship without one. Tax brackets are not a choice: they are law,
 * the authoring form REQUIRES one, and a platform booted without them blocks
 * every seller on an empty required select. Same reasoning as
 * `LocalizationSeeder` running first — this is data the application cannot work
 * without.
 *
 * `firstOrCreate`, NOT `updateOrCreate`, keyed on the immutable `code`. The rate
 * is the legally meaningful part and an operator maintains it in the panel: when
 * a government moves %20 to %22 they edit the row, and a seeder that "corrected"
 * it back on the next deploy would silently un-do a compliance change. So this
 * only ever fills in what is MISSING — the `CatalogTaxonomySeeder` principle.
 *
 * THE BACKFILL IS THE OTHER HALF OF THE MIGRATION. `products.tax_rate_id` is
 * nullable because a schema migration cannot point rows at operator-owned lookup
 * data that may not exist yet; this is where existing products get a bracket. It
 * runs every deploy and is a no-op once nothing is missing.
 *
 * @see database/Modules/Catalog/migrations/2026_09_25_000001_create_tax_rates_and_add_product_tax_rate.php
 */
final class TaxRateSeeder extends Seeder
{
    /**
     * The code of the bracket an unclassified product falls back to.
     *
     * THE GENERAL RATE, deliberately, not the lowest one. Guessing low would
     * under-collect KDV on every backfilled product and make the platform's
     * problem the tax office's; guessing the general rate is wrong only for goods
     * that have a named exception, and being visibly wrong upward is the error a
     * seller reports rather than one nobody notices.
     */
    public const string DEFAULT_CODE = 'kdv-20';

    /**
     * Turkey's brackets as of 2023-07-07 (the %8 → %10 / %18 → %20 revision).
     *
     * @var list<array{code: string, name: string, rate: string}>
     */
    private const array BRACKETS = [
        ['code' => 'kdv-20', 'name' => 'KDV %20 (Genel)', 'rate' => '0.2000'],
        ['code' => 'kdv-10', 'name' => 'KDV %10 (İndirimli)', 'rate' => '0.1000'],
        ['code' => 'kdv-1', 'name' => 'KDV %1 (Temel gıda, kitap)', 'rate' => '0.0100'],
        // A real bracket, not an "unset" marker: exports and certain deliveries
        // are genuinely zero-rated, and an order line for one still needs a rate
        // to snapshot (ADR-053) rather than a null to interpret.
        ['code' => 'kdv-0', 'name' => 'KDV %0 (İstisna)', 'rate' => '0.0000'],
    ];

    public function run(): void
    {
        foreach (self::BRACKETS as $bracket) {
            // No uuid passed: HasUuid generates it on create and guards the
            // column, so it stays stable across re-seeds.
            TaxRate::query()->firstOrCreate(
                ['code' => $bracket['code']],
                [
                    'name' => $bracket['name'],
                    'rate' => $bracket['rate'],
                    'is_active' => true,
                ],
            );
        }

        $this->backfillProducts();
    }

    /**
     * Give every product still without a bracket the general one.
     *
     * A SET-BASED UPDATE, not a loop: the catalog can hold millions of rows and
     * this runs on every deploy. It touches nothing once the column is populated,
     * which is what makes re-running it free.
     */
    private function backfillProducts(): void
    {
        $default = TaxRate::query()->where('code', self::DEFAULT_CODE)->first();

        if ($default === null) {
            return;
        }

        Product::query()
            ->withTrashed()
            ->whereNull('tax_rate_id')
            // `update()` and not a save loop, so no model events fire: a backfill
            // is not a product edit and must not land in the audit trail as one,
            // nor re-index the whole catalog for search.
            ->update(['tax_rate_id' => $default->getKey()]);
    }
}
