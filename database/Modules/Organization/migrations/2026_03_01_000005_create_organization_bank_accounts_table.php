<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An organization's payout bank account(s).
 *
 * REVISION-FRIENDLY FOR MULTIPLE ACCOUNTS (Phase 5 note): the schema permits
 * several accounts per organization — `is_primary` distinguishes the active
 * payout destination and `label` names them — but the application currently
 * enables exactly ONE (the primary). A partial unique index guarantees at most
 * one primary, live account per org, so enabling multiples later is a feature
 * flag, not a breaking migration.
 *
 * `iban` holds ciphertext (the model's `encrypted` cast) — a `text` column
 * because encryption inflates the length well beyond a raw IBAN.
 *
 * @see App\Modules\Organization\Domain\Models\OrganizationBankAccount
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // NOT unique — several accounts per org are allowed; the app enables
            // one (the primary) for now.
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('label')->nullable();
            $table->boolean('is_primary')->default(true);

            $table->string('account_holder');
            // Encrypted at rest — ciphertext, so text not a sized string.
            $table->text('iban');
            $table->string('bank_name')->nullable();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();

            $table->timestampTz('verified_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('organization_id');
        });

        // At most one PRIMARY, non-deleted account per organization — the "one
        // account now" guarantee, enforced by the database, not just the app.
        DB::statement(
            'CREATE UNIQUE INDEX organization_bank_accounts_one_primary '
            .'ON organization_bank_accounts (organization_id) '
            .'WHERE is_primary AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_bank_accounts');
    }
};
