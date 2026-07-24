<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only record of what changed on which record, by whom.
 *
 * @see App\Modules\Audit\Domain\Models\AuditEntry
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('event', 20);   // created|updated|deleted|restored

            // The record that changed. Polymorphic rather than a FK per table:
            // any model may opt in with the Auditable trait, and a column per
            // auditable table would be unmaintainable.
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');

            // Nullable: system writes (seeders, workers, console) genuinely
            // have no causer, and inventing one would make the trail lie.
            $table->string('causer_type')->nullable();
            $table->unsignedBigInteger('causer_id')->nullable();

            /*
            | jsonb, not json — PostgreSQL can index into it, which is what
            | makes "every price change on this record" a query rather than a
            | full scan. Only CHANGED attributes are stored, so an idempotent
            | save writes nothing.
            */
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('browser', 32)->nullable();
            $table->string('platform', 32)->nullable();
            $table->string('url', 512)->nullable();

            // Ties this change to the request or job that caused it, and to
            // every log line and event from the same run.
            $table->uuid('correlation_id')->nullable();

            // Append-only — no updated_at. @see AuditEntry::UPDATED_AT
            $table->timestampTz('created_at')->nullable();

            /*
            | The three access patterns:
            |   history of one record          (the record's audit tab)
            |   everything one user did        (the user's audit tab)
            |   everything in one request      (incident forensics)
            */
            $table->index(['auditable_type', 'auditable_id', 'created_at'], 'audit_auditable_idx');
            $table->index(['causer_type', 'causer_id', 'created_at'], 'audit_causer_idx');
            $table->index('correlation_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
    }
};
