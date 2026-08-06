<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The column the review gate joins on (ADR-067, Reviews R2).
 *
 * `order_lines` was indexed on `variant_uuid` and `offer_uuid` and NOT on
 * `product_uuid`, which was right for every read that existed: an order's lines
 * are fetched by `order_id`, and nothing asked "which lines are for this
 * product?".
 *
 * `deliveredPurchaseLines()` asks exactly that, on behalf of a shopper opening a
 * product page while signed in — so the column goes from never-filtered to
 * filtered on a customer-facing path, and an index is the difference between a
 * lookup and a scan of every line ever sold.
 *
 * IT IS A PLAIN INDEX ON ORDER'S OWN TABLE. Order is not frozen, nothing about
 * the schema's meaning changes, and no other module can see it — Reviews reaches
 * these rows only through the Core contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->index('product_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->dropIndex(['product_uuid']);
        });
    }
};
