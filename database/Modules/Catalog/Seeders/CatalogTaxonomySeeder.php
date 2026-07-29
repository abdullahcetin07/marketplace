<?php

declare(strict_types=1);

namespace Database\Modules\Catalog\Seeders;

use App\Modules\Catalog\Domain\Enums\AttributeType;
use App\Modules\Catalog\Domain\Models\Attribute;
use App\Modules\Catalog\Domain\Models\AttributeValue;
use App\Modules\Catalog\Domain\Models\Category;
use Illuminate\Database\Seeder;

/**
 * A STARTING POINT for the taxonomy, not a fixture (§13.3, ruled).
 *
 * The Category Manager owns the taxonomy (ADR-038), which means the platform
 * cannot ship with an empty one — there would be nowhere to put the first
 * product, and the first seller would be blocked on a human. So this seeds a
 * small top-level tree and the handful of attributes almost every catalog needs.
 *
 * WHAT THIS IS NOT: a committed structure sellers may depend on. Every row here
 * is meant to be renamed, re-parented, deactivated or deleted by a Category
 * Manager on day one. Nothing in the application references these slugs or
 * codes, deliberately — the moment code did, an operator editing the taxonomy
 * would break it.
 *
 * IDEMPOTENT, keyed on `slug` and `code`, so it is safe on every deploy and
 * re-running it never duplicates a branch or resets an operator's edits to the
 * names — it only fills in what is missing.
 *
 * Not registered in `DatabaseSeeder`: an operator runs it once when opening the
 * catalog, and a test that wants a taxonomy builds exactly the one it is
 * testing. @see docs/modules/Catalog.md §13.3
 */
final class CatalogTaxonomySeeder extends Seeder
{
    /**
     * Top-level categories with one level of children, so the tree has real
     * leaves to attach products to out of the box.
     *
     * @var array<string, array{tr: string, en: string, children: array<string, array{tr: string, en: string}>}>
     */
    private const array TREE = [
        'giyim' => [
            'tr' => 'Giyim', 'en' => 'Clothing',
            'children' => [
                'kadin-giyim' => ['tr' => 'Kadın Giyim', 'en' => 'Women\'s Clothing'],
                'erkek-giyim' => ['tr' => 'Erkek Giyim', 'en' => 'Men\'s Clothing'],
                'cocuk-giyim' => ['tr' => 'Çocuk Giyim', 'en' => 'Kids\' Clothing'],
            ],
        ],
        'ayakkabi-canta' => [
            'tr' => 'Ayakkabı & Çanta', 'en' => 'Shoes & Bags',
            'children' => [
                'ayakkabi' => ['tr' => 'Ayakkabı', 'en' => 'Shoes'],
                'canta' => ['tr' => 'Çanta', 'en' => 'Bags'],
            ],
        ],
        'elektronik' => [
            'tr' => 'Elektronik', 'en' => 'Electronics',
            'children' => [
                'telefon' => ['tr' => 'Telefon', 'en' => 'Phones'],
                'bilgisayar' => ['tr' => 'Bilgisayar', 'en' => 'Computers'],
                'televizyon' => ['tr' => 'Televizyon', 'en' => 'Televisions'],
            ],
        ],
        'ev-yasam' => [
            'tr' => 'Ev & Yaşam', 'en' => 'Home & Living',
            'children' => [
                'mobilya' => ['tr' => 'Mobilya', 'en' => 'Furniture'],
                'mutfak' => ['tr' => 'Mutfak', 'en' => 'Kitchen'],
            ],
        ],
        'kozmetik' => [
            'tr' => 'Kozmetik & Kişisel Bakım', 'en' => 'Cosmetics & Personal Care',
            'children' => [
                'makyaj' => ['tr' => 'Makyaj', 'en' => 'Make-up'],
                'parfum' => ['tr' => 'Parfüm', 'en' => 'Fragrance'],
            ],
        ],
    ];

    /**
     * The attributes a Turkish marketplace needs before it can list anything.
     *
     * Renk and Beden are `select` and variant-defining — they are the axes
     * clothing and footwear are unsellable without (ADR-039). Malzeme and
     * Garanti Süresi are descriptive, and are here to prove the schema carries
     * non-variant attributes too.
     *
     * @var array<string, array{tr: string, en: string, type: string, variant: bool, values: array<string, array{tr: string, en: string}>}>
     */
    private const array ATTRIBUTES = [
        'renk' => [
            'tr' => 'Renk', 'en' => 'Colour',
            'type' => 'select', 'variant' => true,
            'values' => [
                'siyah' => ['tr' => 'Siyah', 'en' => 'Black'],
                'beyaz' => ['tr' => 'Beyaz', 'en' => 'White'],
                'kirmizi' => ['tr' => 'Kırmızı', 'en' => 'Red'],
                'mavi' => ['tr' => 'Mavi', 'en' => 'Blue'],
                'yesil' => ['tr' => 'Yeşil', 'en' => 'Green'],
                'gri' => ['tr' => 'Gri', 'en' => 'Grey'],
            ],
        ],
        'beden' => [
            'tr' => 'Beden', 'en' => 'Size',
            'type' => 'select', 'variant' => true,
            'values' => [
                'xs' => ['tr' => 'XS', 'en' => 'XS'],
                's' => ['tr' => 'S', 'en' => 'S'],
                'm' => ['tr' => 'M', 'en' => 'M'],
                'l' => ['tr' => 'L', 'en' => 'L'],
                'xl' => ['tr' => 'XL', 'en' => 'XL'],
            ],
        ],
        'malzeme' => [
            'tr' => 'Malzeme', 'en' => 'Material',
            'type' => 'select', 'variant' => false,
            'values' => [
                'pamuk' => ['tr' => 'Pamuk', 'en' => 'Cotton'],
                'polyester' => ['tr' => 'Polyester', 'en' => 'Polyester'],
                'deri' => ['tr' => 'Deri', 'en' => 'Leather'],
                'yun' => ['tr' => 'Yün', 'en' => 'Wool'],
            ],
        ],
        'garanti-suresi' => [
            'tr' => 'Garanti Süresi (ay)', 'en' => 'Warranty (months)',
            'type' => 'number', 'variant' => false,
            'values' => [],
        ],
    ];

    public function run(): void
    {
        $this->seedCategories();
        $this->seedAttributes();
    }

    private function seedCategories(): void
    {
        $position = 0;

        foreach (self::TREE as $slug => $node) {
            $parent = $this->category($slug, $node['tr'], $node['en'], null, $position++);

            $childPosition = 0;

            foreach ($node['children'] as $childSlug => $child) {
                $this->category($childSlug, $child['tr'], $child['en'], $parent, $childPosition++);
            }
        }
    }

    /**
     * Create or leave alone one node.
     *
     * The path cannot be computed before the row exists (it contains the id), so
     * it is written back immediately after — the same two-step the factory
     * takes, and both defer to `Category::pathFor()` for the format.
     */
    private function category(string $slug, string $tr, string $en, ?Category $parent, int $position): Category
    {
        $existing = Category::query()->where('slug', $slug)->first();

        if ($existing !== null) {
            return $existing;
        }

        $category = Category::query()->create([
            'parent_id' => $parent?->getKey(),
            'path' => Category::PATH_SEPARATOR,
            'depth' => Category::depthFor($parent),
            'name_tr' => $tr,
            'name_en' => $en,
            'slug' => $slug,
            'is_active' => true,
            /*
            | ADR-047 — the starter taxonomy ships with the flag set the way the
            | migration set it for existing data: the second level accepts
            | products, the top-level containers do not. A Category Manager
            | opening *Kozmetik* itself is a deliberate act, not a default.
            */
            'accepts_products' => $parent !== null,
            'position' => $position,
        ]);

        $category->forceFill([
            'path' => Category::pathFor($parent, (int) $category->getKey()),
        ])->save();

        return $category;
    }

    private function seedAttributes(): void
    {
        $position = 0;

        foreach (self::ATTRIBUTES as $code => $definition) {
            $attribute = Attribute::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name_tr' => $definition['tr'],
                    'name_en' => $definition['en'],
                    'type' => AttributeType::from($definition['type']),
                    'is_variant_defining' => $definition['variant'],
                    'is_filterable' => true,
                    'is_active' => true,
                    'position' => $position++,
                ],
            );

            $valuePosition = 0;

            foreach ($definition['values'] as $value => $labels) {
                AttributeValue::query()->firstOrCreate(
                    ['attribute_id' => $attribute->getKey(), 'value' => $value],
                    [
                        // No `uuid` here: HasUuid generates it and guards the
                        // column against mass assignment.
                        'label_tr' => $labels['tr'],
                        'label_en' => $labels['en'],
                        'is_active' => true,
                        'position' => $valuePosition++,
                    ],
                );
            }
        }
    }
}
