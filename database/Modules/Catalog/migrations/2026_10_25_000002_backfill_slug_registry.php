<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Enums\SluggableType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every existing product, category and brand claims its public address
 * (ADR-059).
 *
 * A MIGRATION, NOT A SEEDER, and the distinction is the point. Seeded data on
 * this platform is operator-owned — tax brackets, a starter taxonomy, demo
 * attributes — and skipping it costs a feature. Skipping THIS costs every URL:
 * the storefront's catch-all resolves through the registry, so an unregistered
 * product is a 404 for a page that exists. It has to run when the code that needs
 * it deploys, which is what `migrate` guarantees and `db:seed` does not.
 *
 * CATEGORIES, THEN BRANDS, THEN PRODUCTS (`SluggableType::claimOrder()`). The
 * three now compete for one namespace and somebody must lose a name: a category
 * is the most navigational URL and there are the fewest of them, a product title
 * is the longest and least likely to collide. So a collision costs a numeric
 * suffix on the row that matters least.
 *
 * IT REWRITES THE ENTITY'S OWN `slug` COLUMN WHEN IT SUFFIXES, so the column and
 * the registry cannot disagree. A registry saying `/dermokozmetik-2` while the
 * product row still says `dermokozmetik` would produce a page whose canonical
 * link points somewhere it does not live.
 *
 * IT USES THE QUERY BUILDER, NOT THE MODELS, deliberately: `HasRegisteredSlug`
 * hooks `saved` and would try to register each row a second time mid-backfill,
 * and a migration that depends on model events is one that breaks the day
 * somebody adds another.
 *
 * RESERVED WORDS ARE RE-CHECKED HERE TOO. A category called "Sepet" predates this
 * rule and would otherwise shadow the basket page — silently, since the
 * storefront's static route wins and the category simply becomes unreachable.
 *
 * @see App\Modules\Catalog\Infrastructure\Registries\SlugRegistry
 * @see docs/Architecture_Decision_Record.md ADR-059
 */
return new class extends Migration
{
    public function up(): void
    {
        /** @var array<int, string> $reserved */
        $reserved = array_map('mb_strtolower', (array) config('catalog.slugs.reserved', []));

        // Slugs claimed so far in this run, so the loop does not have to query
        // the table it is writing to for every row.
        $claimed = [];
        $now = now();

        foreach (SluggableType::claimOrder() as $type) {
            $rows = DB::table($this->tableFor($type))
                ->select(['id', 'slug'])
                ->orderBy('id')
                ->get();

            $registry = [];
            $renames = [];

            foreach ($rows as $row) {
                $base = Str::slug((string) $row->slug);

                if ($base === '') {
                    $base = $type->value;
                }

                $slug = $base;
                $suffix = 2;

                while (isset($claimed[$slug]) || in_array($slug, $reserved, true)) {
                    $slug = $base.'-'.$suffix;
                    $suffix++;
                }

                $claimed[$slug] = true;

                $registry[] = [
                    'slug' => $slug,
                    'sluggable_type' => $type->value,
                    'sluggable_id' => (int) $row->id,
                    'is_canonical' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if ($slug !== (string) $row->slug) {
                    $renames[(int) $row->id] = $slug;
                }
            }

            foreach (array_chunk($registry, 500) as $chunk) {
                DB::table('slugs')->insert($chunk);
            }

            // One statement per changed row, but only for rows that actually
            // changed — on a clean catalogue this loop runs zero times.
            foreach ($renames as $id => $slug) {
                DB::table($this->tableFor($type))->where('id', $id)->update(['slug' => $slug]);
            }
        }
    }

    public function down(): void
    {
        /*
        | THE ENTITY SLUGS ARE NOT RESTORED, and saying so is more honest than a
        | reverse migration that pretends. The pre-backfill spelling of a suffixed
        | slug is not recoverable from here, and re-introducing a duplicate slug
        | on the way down would leave the catalogue in a state the flat URL scheme
        | cannot serve. Emptying the registry is the reversible half.
        */
        DB::table('slugs')->truncate();
    }

    private function tableFor(SluggableType $type): string
    {
        return match ($type) {
            SluggableType::Product => 'products',
            SluggableType::Category => 'categories',
            SluggableType::Brand => 'brands',
        };
    }
};
