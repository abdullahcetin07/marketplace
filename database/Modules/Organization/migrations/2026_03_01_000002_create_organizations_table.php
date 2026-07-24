<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The legal seller company (ADR-028).
 *
 * @see App\Modules\Organization\Domain\Models\Organization
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // The sole Owner — NOT NULL (an org cannot exist without one, §3.9).
            // restrictOnDelete: a user who owns an org cannot be deleted out from
            // under it; ownership must be transferred first.
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();

            $table->string('legal_name');
            $table->string('display_name')->nullable();
            $table->string('slug')->unique();

            // Domain-specific lifecycle enum, not the shared Status.
            $table->string('status', 20)->default('pending')->index();

            // Null plan → the system default limit applies. nullOnDelete because
            // plans are retired by is_active, and a stray delete must not orphan.
            $table->foreignId('plan_id')->nullable()->constrained('organization_plans')->nullOnDelete();

            // Admin-granted bespoke allowance; wins over the plan (ADR-028).
            $table->unsignedInteger('store_limit_override')->nullable();

            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();

            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();

            // Admin actors on lifecycle transitions.
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('suspended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            // One organization per owner is enforced in the action layer; this
            // index serves the "my organization" lookup.
            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
