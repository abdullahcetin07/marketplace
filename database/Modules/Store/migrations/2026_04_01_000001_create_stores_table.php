<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The storefront (ADR-028/032/033).
 *
 * `opening_request_uuid` is UNIQUE — it is the idempotency key that guarantees
 * one store per approved request (ADR-032). `organization_id` carries a FK for
 * integrity only; the Store model exposes no `organization()` relation (ADR-033).
 * `organization_uuid` is denormalised so the public/cross-context surfaces never
 * back-query Organization.
 *
 * @see App\Modules\Store\Domain\Models\Store
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Ownership by id + uuid (ADR-033). The FK is integrity-only; there
            // is no code relation to Organization. cascadeOnDelete: a store
            // cannot outlive the company that owns it.
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->uuid('organization_uuid');

            // The approved request that created this store — the authoritative
            // link back, and the idempotency key (ADR-032). No FK: Store does
            // not own the store_opening_requests table (ADR-033).
            $table->uuid('opening_request_uuid')->unique();

            $table->string('name');
            $table->string('slug')->unique();
            $table->string('store_number')->unique();

            // Domain-specific lifecycle enum, not the shared Status.
            $table->string('status', 20)->default('draft')->index();

            // What the store was before an admin suspended it, so reinstatement
            // restores the exact prior state rather than guessing Active (§7).
            $table->string('status_before_suspension', 20)->nullable();

            // Storefront localization — resolves to the platform defaults at
            // creation; the seller reconfigures later (§4.3). Timezone is
            // nullable because the platform default timezone may be unset.
            $table->foreignId('default_language_id')->constrained('languages')->restrictOnDelete();
            $table->foreignId('default_currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('timezone_id')->nullable()->constrained('timezones')->nullOnDelete();

            // Operational-state timestamps.
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('paused_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();

            // Admin actor + reason on suspension (ADR-027).
            $table->foreignId('suspended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('suspension_reason')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            // The "stores of this organization" lookup, filtered by state.
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
