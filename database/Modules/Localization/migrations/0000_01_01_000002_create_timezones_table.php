<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @see App\Modules\Localization\Domain\Models\Timezone
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timezones', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // IANA identifier — the only value safe to compute a time from.
            $table->string('name', 64)->unique();
            $table->string('label');

            /*
            | Cached offset for SORTING AND DISPLAY ONLY. It is wrong for half
            | the year in any DST zone. Timezone::currentOffsetMinutes()
            | recomputes it live; never derive a timestamp from this column.
            */
            $table->integer('offset_minutes')->default(0);

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestampsTz();

            $table->index(['is_active', 'offset_minutes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timezones');
    }
};
