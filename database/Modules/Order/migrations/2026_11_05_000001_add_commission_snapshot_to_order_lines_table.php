<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a commission is computed FROM, and what it came to — both frozen on the
 * line (ADR-061, Payment.md §6).
 *
 * TWO SNAPSHOTS, TAKEN AT DIFFERENT MOMENTS, and the split is the whole design.
 * The CLASSIFICATION (brand, category, ancestry) is frozen at **checkout**,
 * because it is what the rules are matched against and a product re-categorised
 * next month must not change which rule applied to a sale already made. The
 * COMMISSION (rate, kuruş) is frozen at **payment**, because that is when money
 * actually changes hands and a rule edited before anyone paid should still take
 * effect.
 *
 * ORDER-OWNED COLUMNS FOR PAYMENT'S SAKE — the same shape ADR-055 used when
 * Catalog gained `tax_rate_id` for Order. The alternative is a parallel
 * commission table keyed to `order_line_uuid`, which is the same data one join
 * further away and one more thing that can disagree with the line it describes.
 *
 * `commission_rate` IS DECIMAL AND `commission_minor` IS AN INTEGER, which is the
 * money rule stated in two columns (ADR-005): a percentage is a rate, and the lira
 * it produces are kuruş. A DECIMAL commission amount here would be the financial
 * bug the whole convention exists to prevent.
 *
 * NULLABLE, ALL OF THEM. Every line placed before this migration has no
 * classification and no commission, and back-filling them would mean guessing a
 * taxonomy that has since moved and a rate that was never agreed. A null
 * classification simply cannot match a scoped rule, so those lines fall through to
 * the platform default — which is the honest answer rather than an invented one.
 *
 * @see docs/modules/Payment.md §6
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            /*
            | FROZEN AT CHECKOUT — what the commission rules are matched against.
            | Bare uuids, not foreign keys: Order references other contexts by
            | uuid (ADR-040) and a snapshot containing a foreign key is not a
            | snapshot.
            */
            $table->uuid('brand_uuid')->nullable()->after('product_uuid');
            $table->uuid('category_uuid')->nullable()->after('brand_uuid');

            /*
            | THE CATEGORY'S ANCESTRY, root first and including itself.
            |
            | A commission rule on a parent category covers its descendants
            | (Payment.md §6), so "does this rule apply" becomes "is the rule's
            | category anywhere in this line's ancestry" — answerable from the line
            | alone. Walking the live tree at payment time instead would let a
            | taxonomy reorganised in between change which rule a settled sale
            | matched.
            |
            | JSON rather than a join table because it is a SNAPSHOT: it is never
            | queried across rows, only read back for the line that owns it.
            */
            $table->jsonb('category_path_uuids')->nullable()->after('category_uuid');

            /*
            | FROZEN AT PAYMENT — what was actually charged. A later rule change
            | re-prices the NEXT sale, never this one: a commission a seller has
            | already seen deducted must not move.
            |
            | `commission_rate` mirrors `tax_rate` beside it — same DECIMAL(6,4)
            | shape, same reason (ADR-005): a ratio, not a percentage integer, so
            | 0.1500 is 15%.
            */
            $table->decimal('commission_rate', 6, 4)->nullable()->after('tax_rate');
            $table->unsignedBigInteger('commission_minor')->nullable()->after('line_total_minor');
            $table->timestampTz('commission_resolved_at')->nullable()->after('commission_minor');
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->dropColumn([
                'brand_uuid',
                'category_uuid',
                'category_path_uuids',
                'commission_rate',
                'commission_minor',
                'commission_resolved_at',
            ]);
        });
    }
};
