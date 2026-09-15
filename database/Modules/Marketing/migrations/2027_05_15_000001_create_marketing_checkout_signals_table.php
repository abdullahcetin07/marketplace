<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The browser's match keys for one checkout, kept until the payment settles
 * (Marketing.md §"Browser signals").
 *
 * **THE CALLBACK HAS NO BROWSER.** PayTR calls the server; there is no IP, user
 * agent or Meta cookie on that request. The pay request is the last moment the
 * shopper's browser is talking to the platform, so that is where these are taken
 * and this table is how they survive until `PaymentSucceeded`.
 *
 * **ONE ROW PER CHECKOUT GROUP**, unique — a retried pay overwrites rather than
 * stacking, and the latest browser state wins (consent withdrawn between attempts
 * clears the cookies).
 *
 * **SHORT-LIVED BY DESIGN.** Pruned after `marketing.meta.signal_retention_days`
 * (routes/console.php). This is personal data kept for one purpose; the purpose
 * is over within minutes of payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_checkout_signals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->uuid('checkout_group_uuid')->unique();

            // Present only when the shopper accepted marketing cookies.
            $table->string('fbp', 255)->nullable();
            $table->string('fbc', 500)->nullable();

            $table->string('client_ip', 45)->nullable();
            $table->string('client_user_agent', 512)->nullable();

            $table->timestamps();
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_checkout_signals');
    }
};
