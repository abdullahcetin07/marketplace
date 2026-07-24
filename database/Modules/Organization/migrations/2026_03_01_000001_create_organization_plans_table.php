<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription plans — the store-allowance tiers (ADR-028).
 *
 * A lookup table: an operator adds or re-limits a plan without a release.
 * `store_limit` is nullable — null means unlimited.
 *
 * @see App\Modules\Organization\Domain\Models\OrganizationPlan
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_plans', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('name');
            $table->string('slug')->unique();

            // Nullable = unlimited (Enterprise). A real answer, not "unset".
            $table->unsignedInteger('store_limit')->nullable();

            // Lookup tables use is_active, not status (ADR-015).
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_plans');
    }
};
