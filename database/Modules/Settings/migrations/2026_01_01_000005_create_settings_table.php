<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business-configurable settings.
 *
 * ONE TEXT COLUMN, NOT A COLUMN PER TYPE. A typed column set would mean a
 * migration every time a new kind of setting appears, and a nullable column
 * per type on every row. The cost is that `false`, `0` and `"0"` are
 * indistinguishable on the way out — which is exactly why `type` is stored
 * alongside and applied on read.
 *
 * @see App\Modules\Settings\Domain\Models\Setting
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Lowercase dotted path: 'company.name', 'security.session_lifetime'.
            $table->string('key')->unique();

            $table->string('group', 32)->index();
            $table->string('type', 16)->default('string');

            /*
            | Nullable: null means "never set, use default_value". That is
            | distinct from an empty string, which is a deliberate blank.
            | Collapsing the two would make "reset to default" impossible.
            */
            $table->text('value')->nullable();
            $table->text('default_value')->nullable();

            $table->string('label')->nullable();
            $table->string('description', 512)->nullable();

            // Exposable without authentication — but only in combination with
            // a publicly-readable group. Two gates. @see Setting::isPubliclyReadable()
            $table->boolean('is_public')->default(false);

            // Third-party credentials that belong to the business rather than
            // the deployment (SMTP password, SMS API key).
            $table->boolean('is_encrypted')->default(false);

            // Seeded infrastructure read by code, by key. Displayable but not
            // editable or deletable — renaming one is a runtime null deref.
            $table->boolean('is_locked')->default(false);

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestampsTz();

            $table->index(['group', 'sort_order']);
            $table->index('is_public');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
