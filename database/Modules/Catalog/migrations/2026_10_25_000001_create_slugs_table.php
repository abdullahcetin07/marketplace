<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The global slug registry — one namespace for every public storefront address
 * (ADR-059).
 *
 * WHY A REGISTRY AND NOT THREE `slug` COLUMNS. The columns still exist and still
 * hold each entity's canonical slug; what they cannot do is be unique ACROSS
 * entities. The storefront addresses product, category and brand at the ROOT —
 * `/bioderma`, `/cilt-bakimi`, `/avene-...-krem` — so a brand and a category
 * competing for "dermokozmetik" is not a theoretical clash, it is two pages at
 * one URL. A single unique index over one table is the only thing that can refuse
 * that, and it refuses it at the database rather than in whichever action
 * remembered to check.
 *
 * IT ALSO CARRIES THE ALIAS TRAIL. A slug is stable once issued: renaming a
 * product does not move its URL, because a URL that moves silently is a URL that
 * 404s for everyone who linked to it. When a slug genuinely must change, the new
 * row is written canonical and the OLD ROW IS KEPT with `is_canonical = false`,
 * pointing at the same entity — which is what lets the resolver answer "this is
 * the right thing, but at a different address" and the storefront issue a 301
 * instead of a 404.
 *
 * SO A ROW IS NEVER DELETED ON RENAME, only demoted. The cost is a table that
 * only grows, which is the correct trade for link rot: an alias row is ~60 bytes
 * and a dead inbound link is permanent.
 *
 * `sluggable_type` IS A SHORT STRING ('product'), NOT A CLASS NAME. It is the
 * public wire format the resolver returns, and a fully-qualified class in a
 * payload leaks the application's shape and breaks when the class moves.
 *
 * NO FOREIGN KEY, and that is deliberate rather than lazy: one column cannot
 * reference three tables, and the alternative — three nullable FKs — would let a
 * row point at two things at once. Referential integrity is kept by the cascade
 * the owning aggregates already have (deleting a category deletes its slugs
 * through the registry's own cleanup), and an orphaned row resolves to a missing
 * model, which the resolver reports as a 404 exactly like an unknown slug.
 *
 * @see App\Modules\Catalog\Domain\Models\Slug
 * @see docs/Architecture_Decision_Record.md ADR-059
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slugs', function (Blueprint $table): void {
            $table->id();

            /*
            | THE UNIQUE INDEX THIS TABLE EXISTS FOR. Global, across all three
            | kinds — the flat URL scheme's entire cost, paid in one line.
            |
            | Long enough for a real product title: "Bioderma Sensibio AR+ CC
            | Cream SPF50+ Güneş Koruyuculu Kızarıklık Karşıtı Renkli Nemlendirici
            | Krem 40 ML" slugs to 118 characters, and 255 is the standard
            | Laravel string that every engine indexes without complaint.
            */
            $table->string('slug')->unique();

            $table->string('sluggable_type', 20);
            $table->unsignedBigInteger('sluggable_id');

            /*
            | Exactly one canonical row per entity; every other row for it is a
            | retired alias that 301s here. NOT enforced by a partial unique index
            | — that is expressible on pgsql and not on SQLite, and enforcing it in
            | one engine only would mean the suite could never exercise the
            | constraint production relies on. The registry demotes the old row
            | inside the same transaction that writes the new one.
            */
            $table->boolean('is_canonical')->default(true);

            $table->timestampsTz();

            // "Which slugs belong to this entity" — the read the registry makes on
            // every issue and every rename, and the one an entity's deletion uses.
            $table->index(['sluggable_type', 'sluggable_id', 'is_canonical'], 'slugs_owner_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slugs');
    }
};
