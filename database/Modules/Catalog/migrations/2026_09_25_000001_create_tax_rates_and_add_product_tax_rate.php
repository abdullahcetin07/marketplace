<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-056 — the KDV bracket a product belongs to (Catalog.md §2.4, driven by Order).
 *
 * WHY THIS IS NOT A BREACH OF "A PRODUCT HAS NO PRICE" (ADR-037). A tax bracket
 * is a **classification of the product**, decided by law and by what the thing
 * IS — a book is %1 whoever sells it, at whatever price. It is not a commercial
 * term the seller chooses, which is exactly the test that keeps price and stock
 * out of this module. Nothing here says what anything costs.
 *
 * WHY A TABLE AND NOT AN ENUM (the "enum or table?" rule). Brackets change by
 * government decision, not by release: Turkey moved %8 → %10 and %18 → %20 in
 * July 2023 with days of notice. An operator must be able to add a bracket and
 * deactivate an old one without a deploy, and `is_active` is the lookup-table
 * marker ADR-015 mandates.
 *
 * THE COLUMN IS NULLABLE, deliberately, and that is the one thing worth
 * explaining here:
 *
 *   - The rows of a lookup table are OPERATOR-owned, so they belong to a seeder,
 *     not to a schema migration (the `CatalogTaxonomySeeder` precedent). A
 *     migration cannot therefore point existing products at a bracket that may
 *     not exist yet.
 *   - So `TaxRateSeeder` inserts the brackets AND backfills every product still
 *     missing one, and the deploy order in the work order — `migrate` then
 *     `db:seed` — is what completes this change.
 *   - Required is enforced where the product is AUTHORED (the "ürün aç" form)
 *     and re-checked at submission, which is where a human can be told what to
 *     fix. A NOT NULL column would have made this migration undeployable on a
 *     catalog that already has products.
 *
 * @see App\Modules\Catalog\Domain\Models\TaxRate
 * @see Database\Modules\Catalog\Seeders\TaxRateSeeder
 * @see docs/modules/Catalog.md §2.4
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            /*
            | The seeder's idempotency key — the `Attribute.code` pattern. Stable
            | and machine-owned, so re-running the seeder fills in what is missing
            | without resetting an operator's edit to the NAME, which is the
            | string they actually maintain.
            */
            $table->string('code', 40)->unique();

            // What an operator and a seller read: "KDV %20". Not localized — a
            // bracket is a legal label, not marketing copy.
            $table->string('name');

            /*
            | A RATIO, NOT A PERCENTAGE: 0.2000 means %20. Stored that way because
            | it is the form the arithmetic uses — Order extracts the included KDV
            | with `line_total − round(line_total / (1 + rate))` (ADR-055) — and a
            | column that has to be divided by 100 at every call site eventually
            | is not, once.
            |
            | DECIMAL, never a float: a rate multiplied against a large total
            | loses real money to binary rounding (003 §16.1, ADR-005). Scale 4
            | is chosen so the ratio can be scaled to an integer (0.2000 → 2000)
            | and the whole extraction done in integer arithmetic, with no float
            | anywhere near money.
            |
            | NOT UNIQUE, deliberately: two brackets may legitimately share a rate
            | with different names ("Kitap %0" and an export-exempt %0), and the
            | uniqueness that matters is the code.
            */
            $table->decimal('rate', 6, 4);

            // Lookup table ⇒ `is_active`, not `status` (ADR-015). A repealed
            // bracket is deactivated, never deleted: products bought under it
            // still reference it, and their order lines snapshot the rate anyway.
            $table->boolean('is_active')->default(true)->index();

            $table->timestampsTz();
        });

        Schema::table('products', function (Blueprint $table): void {
            /*
            | RESTRICT: a bracket with products on it cannot be deleted.
            | Withdrawing one is `is_active = false`, which leaves every product
            | still pointing at a real rate — the `category_id` reasoning.
            |
            | Nullable for the deploy reason in the class docblock, not because a
            | product without a bracket is acceptable.
            */
            $table->foreignId('tax_rate_id')->nullable()->after('brand_id')
                ->constrained('tax_rates')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tax_rate_id');
        });

        Schema::dropIfExists('tax_rates');
    }
};
