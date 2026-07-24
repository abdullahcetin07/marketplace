<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membership: a user belongs to an organization with a role (ADR-030).
 *
 * @see App\Modules\Organization\Domain\Models\OrganizationMember
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_members', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('role', 20);
            $table->string('status', 20)->default('active');

            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('joined_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            // A user belongs to an organization at most once.
            $table->unique(['organization_id', 'user_id']);
            // "Members of this org" and "orgs of this user" are both hot paths.
            $table->index(['organization_id', 'status']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_members');
    }
};
