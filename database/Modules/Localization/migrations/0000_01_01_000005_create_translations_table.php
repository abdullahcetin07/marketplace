<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-editable translation overrides.
 *
 * These OVERLAY the shipped lang/ files rather than replacing them — the files
 * remain the source of truth for which keys exist, so a new key works with no
 * row here and a bad override can be deleted to fall back.
 *
 * @see App\Modules\Localization\Infrastructure\DatabaseTranslationLoader
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // cascade, not nullOnDelete: an orphaned translation belongs to no
            // locale and can never be resolved or displayed.
            $table->foreignId('language_id')->constrained('languages')->cascadeOnDelete();

            // Lang file name — 'errors', 'enums'. '*' for JSON-style strings.
            $table->string('group', 64)->default('*');
            // Dotted path within the group — 'Status.active'.
            $table->string('key');

            $table->text('value');

            // True when this row overrides a string that ships in a lang file,
            // as opposed to adding one that does not exist there. Lets the
            // admin UI show "modified from default" and offer a revert.
            $table->boolean('is_overridden')->default(false);

            $table->timestampsTz();

            $table->unique(['language_id', 'group', 'key']);
            // The loader always queries by (language, group) — this is the
            // index that keeps translation loading off the slow query log.
            $table->index(['language_id', 'group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};
