<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KYC / legal documents uploaded by an organization.
 *
 * The file itself lives in the media library (private disk); this table is the
 * document's metadata and review state.
 *
 * @see App\Modules\Organization\Domain\Models\OrganizationDocument
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('type', 30);
            $table->string('status', 20)->default('pending')->index();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_notes')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_documents');
    }
};
