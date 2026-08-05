<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The carriers a seller can hand a parcel to (ADR-063, Shipping.md §5).
 *
 * A TABLE, NOT AN ENUM, by the platform's own test of who owns the value: a new
 * carrier appears when a business signs a contract, and an operator must be able
 * to add or retire one without a release. So `is_active` (ADR-015), not a status.
 *
 * THE TRACKING-URL TEMPLATE IS THE REASON THIS TABLE EARNS ITS KEEP. Without it
 * the storefront would have to hard-code a URL per carrier — which means shipping
 * frontend code every time operations adds one, exactly the coupling the table
 * exists to remove. `{tracking_number}` is substituted; a carrier with no public
 * tracking page simply leaves it null and the number is shown as text.
 *
 * NO MONEY ANYWHERE. v1 charges no shipping fee (ADR-063), so there is no rate, no
 * desi, no price column here — and the minor-units rule does not apply to this
 * module at all. A priced flow re-opens Order's frozen totals and is its own ADR.
 *
 * @see App\Modules\Shipping\Domain\Models\CargoCompany
 * @see Database\Modules\Shipping\Seeders\CargoCompanySeeder
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cargo_companies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            /*
            | The seeder's idempotency key — the `TaxRate.code` precedent. Stable
            | and machine-owned, so re-running the seeder fills in what is missing
            | without resetting an operator's edit to the NAME, which is the string
            | they actually maintain.
            */
            $table->string('code', 40)->unique();

            // What a seller picks and a buyer reads. Not localized: a carrier's
            // name is a proper noun, like `Brand.name`.
            $table->string('name');

            /*
            | `https://.../sorgula?kod={tracking_number}` — substituted by whoever
            | renders the link. NULLABLE because a carrier without a public
            | tracking page is a real case, and a template nobody can fill is worse
            | than none: the number is then shown as plain text rather than as a
            | link to a 404.
            */
            $table->string('tracking_url_template', 500)->nullable();

            // Lookup tables use `is_active` (ADR-015). A retired carrier keeps
            // every shipment that already names it — hence the restricting FK on
            // `shipments` — and simply stops being offered.
            $table->boolean('is_active')->default(true)->index();

            // The order a seller sees them in. Operations knows which carrier the
            // sellers use most; alphabetical does not.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cargo_companies');
    }
};
