<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second partial refund of one order is legitimate now (S4).
 *
 * **WHAT THIS INDEX WAS ACTUALLY FOR.** P3 made `(payment_uuid, order_uuid, type)`
 * unique so a RETRIED PAYMENT CALLBACK could not credit a seller twice — PayTR
 * retries until it hears "OK", and a double `sale_credit` is money invented from
 * nothing. It did that job perfectly.
 *
 * **WHY IT CANNOT STAY.** S4 makes a refund line-level: a buyer sends back one
 * shoe today and the other next week, and each return appends its own
 * `refund_debit` for the same (payment, order, type). Legitimately. The index
 * would refuse the second one.
 *
 * **WHERE THE GUARANTEE GOES.** Back to where it always belonged.
 * `CreditSellerLedger` already checks for an existing entry before crediting a
 * paid order — that is the check a retried callback meets, and it predates this
 * index. What is lost is the belt to that pair of braces: an application check
 * can be forgotten, a constraint cannot. Stated rather than glossed, and the
 * compensating test is the idempotent double-callback case in
 * `PaymentCollectionTest`, which asserts one credit after two callbacks.
 *
 * A NON-UNIQUE INDEX REPLACES IT, because the read it served — "what did this
 * payment do to this order" — is still made on every refund.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_ledger_entries', function (Blueprint $table): void {
            $table->dropUnique('seller_ledger_settlement_unique');
            $table->index(['payment_uuid', 'order_uuid'], 'seller_ledger_settlement_index');
        });
    }

    public function down(): void
    {
        Schema::table('seller_ledger_entries', function (Blueprint $table): void {
            $table->dropIndex('seller_ledger_settlement_index');

            /*
            | RESTORED, unlike the refund table's — and only because it CAN be. A
            | rollback that hits duplicate rows will fail loudly here, which is the
            | correct outcome: it means partial refunds exist, and deciding what
            | happens to them is a decision a human makes before rolling back.
            */
            $table->unique(['payment_uuid', 'order_uuid', 'type'], 'seller_ledger_settlement_unique');
        });
    }
};
