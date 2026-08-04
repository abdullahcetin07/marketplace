<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A transfer to a seller — RECORDED, not performed (ADR-062, Payment.md §8).
 *
 * THE SOFTWARE MOVES NO MONEY, and that sentence is the whole table. The platform
 * is a single merchant at the PSP and settles with its sellers by its own means
 * (ADR-060 §2); a row here says "an admin decided to send 9 840 kuruş to this
 * seller", and later "somebody did, and here is the bank's reference". There is no
 * banking integration behind it and v1 does not want one.
 *
 * IT IS THEREFORE A LEDGER OF INTENT AND OUTCOME, not a queue of jobs. Nothing
 * polls this table, nothing retries a `failed` row, and marking one `paid` calls
 * no API.
 *
 * THE BALANCE MOVES WHEN THE ROW IS CREATED, not when it is marked paid. That is
 * the non-obvious choice and it is what makes the guard work: if the debit waited
 * for `paid`, two admins could each create a payout for the whole balance and both
 * would pass their checks, and the seller would be overdrawn when both went
 * through. So `pending` already holds the money out, and `failed` gives it back
 * with a `payout_reversal_credit`.
 *
 * NO FOREIGN KEY TO THE SELLER. Payment imports no module (ADR-060 §9): the
 * organization is a uuid. `created_by` is an internal user id, which is
 * permitted — `app/Models/User` sits above the modules (001 §6) and every module
 * may reference it.
 *
 * `external_reference` IS FREE TEXT ON PURPOSE. It is whatever the bank gave the
 * human — an EFT reference, a swift id, a screenshot's filename. Constraining its
 * shape would mean guessing at every Turkish bank's format and rejecting the one
 * that matters at 5pm on a Friday.
 *
 * @see App\Modules\Payment\Domain\Models\Payout
 * @see docs/modules/Payment.md §8
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->uuid('seller_org_uuid')->index();

            // Kuruş, integer, unsigned — a payout is always a positive amount
            // going out; the SIGN of its effect lives on the ledger entry
            // (ADR-005, ADR-062).
            $table->unsignedBigInteger('amount_minor');
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();

            $table->string('status', 16)->default('pending')->index();

            /*
            | WHAT THE BANK CALLED IT. Free text — see the class docblock. Null
            | while pending, because there is nothing to reference until somebody
            | has actually sent the money.
            */
            $table->string('external_reference')->nullable();

            // Why it was refused, in the operator's own words. There is no code
            // list here: a Turkish bank's rejection is a sentence, not an enum.
            $table->text('failure_reason')->nullable();

            // A human's note — "Temmuz ayı toplu ödeme", "eksik IBAN sonrası".
            $table->string('note')->nullable();

            /*
            | WHO DECIDED, AND WHO CONFIRMED. Two different admins in a real
            | finance process, which is exactly why they are two columns: the one
            | who created the payout is not necessarily the one who watched the
            | transfer land.
            */
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('settled_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('failed_at')->nullable();

            $table->timestampsTz();

            // "What has this seller been paid, and what is still in flight" — the
            // finance screen's read.
            $table->index(['seller_org_uuid', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
