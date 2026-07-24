<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The storefront profile: settings, branding, SEO and contact (§2.2–2.6).
 *
 * Four 1:1 companions to `stores`, each `store_id` UNIQUE + cascade — a store
 * has exactly one of each, and they die with it. Kept as separate tables rather
 * than columns on `stores` so the aggregate row stays lean and each concern
 * evolves independently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained('stores')->cascadeOnDelete();
            $table->text('announcement')->nullable();
            $table->boolean('order_note_enabled')->default(false);
            $table->string('weight_unit', 8)->default('kg');
            $table->string('dimension_unit', 8)->default('cm');
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
        });

        Schema::create('store_branding', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained('stores')->cascadeOnDelete();
            $table->string('primary_color', 9)->nullable();
            $table->string('accent_color', 9)->nullable();
            $table->string('theme')->nullable();
            $table->timestampsTz();
            // Logo/banner/favicon live in the media library (public disk), not here.
        });

        Schema::create('store_seo', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained('stores')->cascadeOnDelete();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->jsonb('meta_keywords')->nullable();
            $table->string('canonical_url')->nullable();
            // Non-live stores are flipped to noindex by the public surface (§5).
            $table->string('robots', 40)->default('index,follow');
            $table->timestampsTz();
        });

        Schema::create('store_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained('stores')->cascadeOnDelete();
            $table->string('public_email')->nullable();
            $table->string('public_phone', 40)->nullable();
            $table->jsonb('address')->nullable();
            $table->jsonb('support_hours')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_contacts');
        Schema::dropIfExists('store_seo');
        Schema::dropIfExists('store_branding');
        Schema::dropIfExists('store_settings');
    }
};
