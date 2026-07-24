<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turn the audit trail into the platform's forensic event store (ADR-027).
 *
 * Before: a model-diff log — every row was a `created|updated|deleted|restored`
 * on some model. After: model changes are ONE category. A row now carries a
 * generic `event_type` (model lifecycle, security, governance) and an
 * independent `severity`, and may stand alone with no model diff — a detected
 * brute-force login has an actor, an IP and a severity, but nothing changed on
 * a record.
 *
 * @see App\Modules\Audit\Domain\Enums\AuditEventType
 * @see App\Modules\Audit\Domain\Enums\AuditSeverity
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_entries', function (Blueprint $table): void {
            // The generic category. Nullable for the backfill below, tightened
            // to NOT NULL once existing rows are classified.
            $table->string('event_type', 40)->nullable()->after('uuid');

            // Independent of type (ADR-027). Info for the ordinary model change;
            // raised deliberately by the writer for security events.
            $table->string('severity', 10)->default('info')->after('event_type');

            // Context for events that are not a model diff — the email an
            // attacker targeted, failure counts, distinct IPs. jsonb so a SIEM
            // export can query into it. A model change leaves this null; its
            // detail lives in old_values/new_values.
            $table->jsonb('metadata')->nullable()->after('new_values');
        });

        /*
        | Existing rows predate the categories, so classify them from the model
        | verb they already carry. Runs before the NOT NULL tightening.
        */
        DB::table('audit_entries')->whereNull('event_type')->update([
            'event_type' => DB::raw("'model_' || event"),
        ]);

        Schema::table('audit_entries', function (Blueprint $table): void {
            // Every row is now classified.
            $table->string('event_type', 40)->nullable(false)->change();

            // A security event has no model verb and may name no record at all —
            // an attack on an address with no account. These were NOT NULL when
            // the table only held model changes.
            $table->string('event', 20)->nullable()->change();
            $table->string('auditable_type')->nullable()->change();
            $table->unsignedBigInteger('auditable_id')->nullable()->change();

            // "Every High-or-worse security event this week" — the SIEM/monitoring
            // access pattern the model-diff indexes never served.
            $table->index(['event_type', 'severity', 'created_at'], 'audit_type_severity_idx');
            $table->index(['severity', 'created_at'], 'audit_severity_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audit_entries', function (Blueprint $table): void {
            $table->dropIndex('audit_type_severity_idx');
            $table->dropIndex('audit_severity_idx');
            $table->dropColumn(['event_type', 'severity', 'metadata']);

            // Restore the pre-ADR-027 NOT NULL shape. Safe only because the
            // rows added under the new shape (standalone security events) would
            // have to be pruned first; down() is a developer convenience, not a
            // production rollback of a forensic store.
            $table->string('event', 20)->nullable(false)->change();
            $table->string('auditable_type')->nullable(false)->change();
            $table->unsignedBigInteger('auditable_id')->nullable(false)->change();
        });
    }
};
