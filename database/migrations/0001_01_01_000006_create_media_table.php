<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spatie/laravel-medialibrary storage table.
 *
 * SCOPE NOTE: Sprint 0 was asked to create no tables beyond users and
 * permissions. This one is included as infrastructure rather than a business
 * table — App\Shared\Traits\HasMedia is part of the required foundation and is
 * inert without it, and no business module can define its media collections
 * until the backing store exists. It holds no domain data of its own.
 *
 * @see App\Shared\Traits\HasMedia
 * @see docs/media.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table): void {
            $table->id();

            $table->morphs('model');
            $table->uuid('uuid')->nullable()->unique();

            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');

            $table->jsonb('manipulations');
            $table->jsonb('custom_properties');
            $table->jsonb('generated_conversions');
            $table->jsonb('responsive_images');

            $table->unsignedInteger('order_column')->nullable()->index();

            $table->nullableTimestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
