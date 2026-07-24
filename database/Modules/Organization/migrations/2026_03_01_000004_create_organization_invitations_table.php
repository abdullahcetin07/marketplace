<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations to join an organization (ADR-031).
 *
 * Stores the token HASH only — never the raw token.
 *
 * @see App\Modules\Organization\Domain\Models\OrganizationInvitation
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_invitations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 20);

            // The ONLY stored form of the token. Unique + indexed so acceptance
            // is a single lookup by the hash of the presented token.
            $table->string('token_hash', 64)->unique();

            $table->string('status', 20)->default('pending')->index();

            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();

            // "Is there a pending invite for this address in this org?"
            $table->index(['organization_id', 'email', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invitations');
    }
};
