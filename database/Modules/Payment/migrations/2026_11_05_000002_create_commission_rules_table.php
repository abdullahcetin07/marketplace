<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commission is not one platform rate (ADR-061, Payment.md §6).
 *
 * THE OWNER COMPOSES RATES BY ADDING ROWS. "Seller X in Kozmetik takes 12%",
 * "Bioderma is 10%", "Kozmetik is 15%", "everything else is 18%" are four rows,
 * and a line matching all four gets the most specific one. That is the entire
 * model: no code changes to add a rate, which is precisely the project's own test
 * for a TABLE rather than an enum (CLAUDE.md), and `is_active` rather than a
 * status because it is a lookup (ADR-015).
 *
 * FOUR NULLABLE SCOPES, AND NULL MEANS "ANY". A rule with all four null is the
 * platform default — not a special row type, not a config key, just the least
 * specific rule there is. That falls out of the resolution rule rather than being
 * a case in it, which is why there is no `is_default` column: two ways to say the
 * same thing is one way for them to disagree.
 *
 * UUIDS, NOT FOREIGN KEYS. Payment imports no module, so a rule names a seller, a
 * product, a brand or a category by uuid and nothing enforces that they exist. The
 * cost is real and accepted: a rule scoped to a deleted product simply stops
 * matching anything, which is the same outcome as deactivating it. The alternative
 * — four FKs into three other modules — is the boundary this platform does not
 * cross.
 *
 * `rate` IS DECIMAL AND ONLY THE RATE IS. A percentage is a ratio (ADR-005); the
 * kuruş it produces are integers, computed at payment and frozen on the order
 * line. There is no money column in this table at all.
 *
 * @see App\Modules\Payment\Domain\Models\CommissionRule
 * @see docs/modules/Payment.md §6
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // A human's note about why this rule exists — "Kozmetik kampanyası",
            // "Anlaşmalı satıcı". Not matched on; it is what makes a list of
            // twenty rates readable a year later.
            $table->string('label')->nullable();

            /*
            | THE FOUR SCOPES. Null = wildcard. Indexed individually rather than
            | composite: resolution loads the ACTIVE candidate set and ranks it in
            | PHP (the set is small and the ranking is not expressible in SQL), so
            | what the database is asked for is "rules that could match this
            | scope", one column at a time.
            */
            $table->uuid('seller_org_uuid')->nullable()->index();
            $table->uuid('product_uuid')->nullable()->index();
            $table->uuid('brand_uuid')->nullable()->index();
            $table->uuid('category_uuid')->nullable()->index();

            // 0.1500 = 15%. Same shape as `tax_rates.rate` and `order_lines.tax_rate`,
            // for the same reason: a ratio the arithmetic can use directly.
            $table->decimal('rate', 6, 4);

            /*
            | THE EXPLICIT OVERRIDE, used only to break a tie between rules of
            | EQUAL specificity. It deliberately cannot beat specificity itself: a
            | priority that could would make "why did this line get 12%?"
            | unanswerable without reading every row, which is the failure mode of
            | every priority-ordered rule engine.
            */
            $table->integer('priority')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestampsTz();

            // The resolution read: active rules, narrowed by scope.
            $table->index(['is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rules');
    }
};
