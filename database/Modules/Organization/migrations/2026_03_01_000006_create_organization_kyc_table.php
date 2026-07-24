<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company verification / KYC data (one per organization).
 *
 * Universal fields are columns; country-specific identifiers (MERSİS, tax
 * office, …) live in `metadata` so a new jurisdiction needs no migration.
 * `authorized_person_national_id` is encrypted — a `text` column for ciphertext.
 *
 * @see App\Modules\Organization\Domain\Models\OrganizationKyc
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_kyc', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();

            // Universal-ish company identifiers.
            $table->string('tax_number')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('authorized_person_name')->nullable();

            // Personal identifier — encrypted ciphertext.
            $table->text('authorized_person_national_id')->nullable();

            // Country-specific extensions (mersis, tax_office, trade_registry…).
            $table->jsonb('metadata')->nullable();

            $table->timestampTz('submitted_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_kyc');
    }
};
