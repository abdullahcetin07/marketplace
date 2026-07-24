<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store Opening Requests (ADR-028).
 *
 * The request lives in Organization; the Store it asks for is created by the
 * future Store module. `created_store_uuid` is a bare UUID reference — no FK to
 * a table this module does not own.
 *
 * @see App\Modules\Organization\Domain\Models\StoreOpeningRequest
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_opening_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();

            $table->string('status', 20)->default('draft')->index();

            $table->string('store_name');
            $table->string('slug');
            // No FK: the category taxonomy arrives with a later module.
            $table->unsignedBigInteger('category_id')->nullable();
            $table->text('description')->nullable();
            $table->text('reason')->nullable();

            $table->text('admin_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();

            // Filled by the Store module when it creates the store — a plain
            // UUID reference, not a foreign key (Organization does not own Store).
            $table->uuid('created_store_uuid')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_opening_requests');
    }
};
