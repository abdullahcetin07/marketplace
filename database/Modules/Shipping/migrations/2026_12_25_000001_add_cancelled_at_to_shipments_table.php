<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The parcel that never left (ADR-065, C1).
 *
 * `returned_at` RECORDS THAT GOODS CAME BACK; THIS RECORDS THAT THEY NEVER WENT.
 * Two columns rather than one nullable "ended_at" plus a status, because the two
 * dates answer different questions and a future cancellation-rate penalty
 * (ADR-065 defers it) has to be able to count one without the other. A seller who
 * cancels before handover has cost the buyer time; one whose delivered parcel came
 * back has spent a carrier fee. Collapsing them would make the cheaper failure
 * indistinguishable from the expensive one.
 *
 * NULLABLE AND NEVER BACKFILLED. No shipment was cancellable before this ADR, so
 * every existing row's honest value is "not cancelled", which is null.
 *
 * @see App\Modules\Shipping\Application\Listeners\CancelShipmentsOnCancellation
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->timestampTz('cancelled_at')->nullable()->after('returned_at');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropColumn('cancelled_at');
        });
    }
};
