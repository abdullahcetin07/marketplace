<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device display adjustment.
 *
 * The platform prioritises usability: a user identifies a device from its
 * operating system, browser, approximate location and last-seen time — not
 * from a name they had to type. User-defined device names are removed as a
 * concept.
 *
 *  - DROP `name` — it existed only to hold a user-assigned label. No endpoint
 *    ever set it, and the feature is cancelled, so it is dead schema.
 *  - ADD `location` — coarse, city-level, populated by a geo-IP listener when
 *    one is configured (the same deferred pattern `user_sessions.location`
 *    already follows). Null until then; the display contract is in place now.
 *
 * The device fingerprint stays an internal implementation detail and is never
 * exposed — enforced in DeviceResource, not here.
 *
 * New migration rather than an edit to the create (§27).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_devices', function (Blueprint $table): void {
            $table->dropColumn('name');
            $table->string('location')->nullable()->after('last_ip');
        });
    }

    public function down(): void
    {
        Schema::table('user_devices', function (Blueprint $table): void {
            $table->dropColumn('location');
            $table->string('name')->nullable()->after('fingerprint');
        });
    }
};
