<?php

declare(strict_types=1);

namespace Database\Modules\Catalog\Seeders;

use App\Modules\Catalog\Application\Actions\BindCategoryAttributeAction;
use App\Modules\Catalog\Application\Actions\SetProductAttributesAction;
use App\Modules\Catalog\Domain\DTOs\BindCategoryAttributeDTO;
use App\Modules\Catalog\Domain\DTOs\ProductAttributeValueDTO;
use App\Modules\Catalog\Domain\Enums\AttributeType;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Models\Attribute;
use App\Modules\Catalog\Domain\Models\AttributeValue;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use Illuminate\Database\Seeder;

/**
 * A DEMO cosmetic attribute schema — and the answer to "why is the spec table
 * empty?".
 *
 * IT WAS NEVER AN AUTHORING GAP. The product page renders `attributes` and always
 * has; the platform simply had four attributes in it — Renk, Beden, Malzeme,
 * Garanti Süresi — all of them clothing or general, and none of the cosmetic
 * categories bound to any of them. A seller cannot enter "Cilt Tipi" because no
 * category has ever offered the field. That ordering is the module's own rule
 * (ADR-038): **the Category Manager defines the attribute on the category, and
 * only then does it appear in the seller's authoring form.**
 *
 * DEMO, NOT TAXONOMY. `CatalogTaxonomySeeder` beside this one is a starting point
 * for an empty platform; this is scaffolding for one live demo, and the owner
 * will curate the real cosmetic schema later. Nothing in the application
 * references these codes.
 *
 * EVERYTHING GOES THROUGH THE REAL ACTIONS — `BindCategoryAttributeAction` and
 * `SetProductAttributesAction` — rather than writing pivot rows. Hand-written
 * rows would be the one path that skips the rules those actions exist to enforce
 * (an attribute must be bound to the product's category; a `select` value must
 * belong to that attribute; a variant axis must be refused), so seeded data would
 * be data no seller could have produced. This way the seed is also a rehearsal of
 * the flow it is documenting.
 *
 * EVERY ATTRIBUTE HERE IS DESCRIPTIVE, never variant-defining. Cilt Tipi and
 * Kullanım are `select` and therefore *eligible* to be axes (ADR-039), which is
 * exactly why the binding says no explicitly: promoting them would multiply out
 * SKUs — a "Kuru × Yüz" variant of a face cream — and this seed must not create
 * a single one.
 *
 * IDEMPOTENT, AND IT NEVER OVERWRITES CURATED CONTENT. Attributes, values and
 * bindings are `firstOrCreate`/upsert, so re-running fills in what is missing and
 * touches nothing else. A product that ALREADY carries attribute values is
 * skipped entirely — `SetProductAttributesAction` is a full replacement by
 * design, and re-running a demo seeder must not silently wipe values a human
 * entered afterwards.
 *
 * Not registered in `DatabaseSeeder`: an operator runs it once. Tests build the
 * fixture they are testing.
 *
 *     php artisan db:seed --class="Database\Modules\Catalog\Seeders\CosmeticAttributeDemoSeeder"
 *
 * @see docs/modules/Catalog.md §2.3, §2.4
 */
final class CosmeticAttributeDemoSeeder extends Seeder
{
    /**
     * The demo schema.
     *
     * Two `select` attributes so the authoring form shows a picker and the
     * values stay consistent across products, and two `text` ones because a
     * volume and a country of origin are not enumerable in any useful way —
     * "40 ml" and "500 ml" are not options to pick between.
     *
     * @var array<string, array{tr: string, en: string, type: string, values: array<string, array{tr: string, en: string}>}>
     */
    public const array ATTRIBUTES = [
        'cilt-tipi' => [
            'tr' => 'Cilt Tipi', 'en' => 'Skin Type',
            'type' => 'select',
            'values' => [
                'kuru' => ['tr' => 'Kuru', 'en' => 'Dry'],
                'yagli' => ['tr' => 'Yağlı', 'en' => 'Oily'],
                'karma' => ['tr' => 'Karma', 'en' => 'Combination'],
                'hassas' => ['tr' => 'Hassas', 'en' => 'Sensitive'],
                'kuru-atopik' => ['tr' => 'Kuru & Atopik', 'en' => 'Dry & Atopic'],
                'normal' => ['tr' => 'Normal', 'en' => 'Normal'],
            ],
        ],
        'hacim' => [
            'tr' => 'Hacim', 'en' => 'Volume',
            'type' => 'text',
            'values' => [],
        ],
        'kullanim' => [
            'tr' => 'Kullanım', 'en' => 'Application Area',
            'type' => 'select',
            'values' => [
                'yuz' => ['tr' => 'Yüz', 'en' => 'Face'],
                'vucut' => ['tr' => 'Vücut', 'en' => 'Body'],
                'sac' => ['tr' => 'Saç', 'en' => 'Hair'],
                'goz-cevresi' => ['tr' => 'Göz Çevresi', 'en' => 'Eye Area'],
            ],
        ],
        'mensei' => [
            'tr' => 'Menşei', 'en' => 'Country of Origin',
            'type' => 'text',
            'values' => [],
        ],
    ];

    /**
     * Categories that get the full cosmetic schema, by slug.
     *
     * BY SLUG, not by id or by name: the slug is the stable handle, a Category
     * Manager may rename a category freely, and a slug that is absent simply
     * means this deployment does not have that branch — which is a skip, not a
     * failure.
     *
     * @var array<int, string>
     */
    public const array COSMETIC_CATEGORY_SLUGS = [
        'cilt-bakim',
        'anti-aging-ve-kirisiklik-karsiti',
        'sac-bakim',
        'vucut-bakim',
        'makyaj',
        'parfum',
    ];

    /**
     * The two real demo products, keyed by GTIN.
     *
     * BY GTIN because it is the catalogue's own dedup key (§3.4) and the only
     * identifier here that is a fact about the physical product rather than
     * about this database — titles get edited and ids are not portable.
     *
     * The values describe THESE products. The work order proposed Bioderma
     * *Atoderm* (a 500 ml body cream for dry/atopic skin); the product actually
     * in the catalogue is *Sensibio AR+ CC Cream*, a 40 ml tinted face cream for
     * sensitive, redness-prone skin. Seeding the proposed values would have put
     * a visibly wrong spec table on the demo's best-looking page, so these match
     * the product on the shelf and the difference is reported.
     *
     * Typed `int` on the key because PHP casts a numeric-string array key to an
     * integer whatever this file writes — a GTIN is a 13-digit NUMBER-looking
     * string that is not a number, and the lookup casts it back rather than
     * pretending otherwise.
     *
     * @var array<int, array<string, string>> gtin => attribute code => value (option value for select)
     */
    public const array PRODUCT_VALUES = [
        // Bioderma Sensibio AR+ CC Cream SPF50+ 40 ml
        '3701129813447' => [
            'cilt-tipi' => 'hassas',
            'hacim' => '40 ml',
            'kullanim' => 'yuz',
            'mensei' => 'Fransa',
        ],
        // Cerave Skin Renewing Retinol Serum 30 ml
        '3337875899543' => [
            'cilt-tipi' => 'karma',
            'hacim' => '30 ml',
            'kullanim' => 'yuz',
            'mensei' => 'ABD',
        ],
    ];

    /**
     * The generic pair given to a couple of the seeded demo products, so the spec
     * table demonstrates on more than one page.
     *
     * ONLY THE CATEGORY-AGNOSTIC ONES. A Faker product called "Eaque Adipisci
     * Nemo" filed under "Minima Fuga" is not a face cream, and binding Cilt Tipi
     * to its category would put a skin type on a product that has no skin — demo
     * data should be thin, not dishonest. A volume and a country of origin are
     * true of nearly anything sold in a box.
     *
     * @var array<string, string>
     */
    public const array GENERIC_VALUES = [
        'hacim' => '250 ml',
        'mensei' => 'Türkiye',
    ];

    /**
     * How many of the seeded demo products get the generic pair.
     */
    public const int GENERIC_PRODUCT_LIMIT = 2;

    public function run(): void
    {
        $attributes = $this->seedAttributes();

        $this->bindCosmeticCategories($attributes);
        $this->valueRealProducts($attributes);
        $this->valueDemoProducts($attributes);
    }

    /**
     * The attribute definitions and their enumerated values.
     *
     * @return array<string, Attribute> keyed by code
     */
    private function seedAttributes(): array
    {
        $attributes = [];

        // After the four starter attributes, so an operator sees Renk/Beden first
        // in any list ordered by position.
        $position = Attribute::query()->max('position') ?? 0;

        foreach (self::ATTRIBUTES as $code => $definition) {
            $attribute = Attribute::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name_tr' => $definition['tr'],
                    'name_en' => $definition['en'],
                    'type' => AttributeType::from($definition['type']),
                    // FALSE GLOBALLY as well as on every binding below. The
                    // global flag is only the default a new binding starts from,
                    // and a default of "yes, make SKUs of this" is the wrong one
                    // to leave lying around for the next category.
                    'is_variant_defining' => false,
                    'is_filterable' => true,
                    'is_active' => true,
                    'position' => ++$position,
                ],
            );

            $valuePosition = 0;

            foreach ($definition['values'] as $value => $labels) {
                AttributeValue::query()->firstOrCreate(
                    ['attribute_id' => $attribute->getKey(), 'value' => $value],
                    [
                        'label_tr' => $labels['tr'],
                        'label_en' => $labels['en'],
                        'is_active' => true,
                        'position' => $valuePosition++,
                    ],
                );
            }

            $attributes[$code] = $attribute->load('values');
        }

        return $attributes;
    }

    /**
     * Bind the whole schema to every cosmetic category that exists here.
     *
     * @param array<string, Attribute> $attributes
     */
    private function bindCosmeticCategories(array $attributes): void
    {
        $bind = app(BindCategoryAttributeAction::class);

        foreach (self::COSMETIC_CATEGORY_SLUGS as $slug) {
            $category = Category::query()->where('slug', $slug)->first();

            if ($category === null) {
                continue;
            }

            $position = 0;

            foreach ($attributes as $attribute) {
                $bind->run($category, new BindCategoryAttributeDTO(
                    attributeUuid: $attribute->uuid,
                    // Nothing is REQUIRED. A required attribute blocks authoring
                    // for every product already filed here, and a demo schema
                    // must not lock a seller out of their own catalogue.
                    isRequired: false,
                    // NEVER an axis — see the class docblock. A cream has no
                    // "Kuru × Yüz" SKU.
                    isVariantDefining: false,
                    isFilterable: true,
                    position: $position++,
                ));
            }
        }
    }

    /**
     * @param array<string, Attribute> $attributes
     */
    private function valueRealProducts(array $attributes): void
    {
        foreach (self::PRODUCT_VALUES as $gtin => $values) {
            $product = Product::query()->where('gtin', (string) $gtin)->first();

            if ($product === null) {
                continue;
            }

            $this->applyValues($product, $attributes, $values);
        }
    }

    /**
     * The generic pair, on a couple of the seeded demo products.
     *
     * The two lowest-id published products that carry no GTIN and no attribute
     * values yet — deterministic for a given database, so re-running picks the
     * same pair rather than spreading demo values across the catalogue.
     *
     * @param array<string, Attribute> $attributes
     */
    private function valueDemoProducts(array $attributes): void
    {
        $bind = app(BindCategoryAttributeAction::class);

        $products = Product::query()
            ->with('category')
            ->where('status', ProductStatus::Published)
            ->whereNull('gtin')
            ->whereDoesntHave('descriptiveAttributes')
            ->orderBy('id')
            ->limit(self::GENERIC_PRODUCT_LIMIT)
            ->get();

        foreach ($products as $product) {
            $position = 0;

            // Their categories are not cosmetic ones, so the pair has to be bound
            // there before a value can be set — the same ordering a Category
            // Manager follows, enforced by `SetProductAttributesAction`.
            foreach (array_keys(self::GENERIC_VALUES) as $code) {
                $bind->run($product->category, new BindCategoryAttributeDTO(
                    attributeUuid: $attributes[$code]->uuid,
                    isRequired: false,
                    isVariantDefining: false,
                    isFilterable: true,
                    position: $position++,
                ));
            }

            $this->applyValues($product, $attributes, self::GENERIC_VALUES);
        }
    }

    /**
     * Set one product's values, unless it already has some.
     *
     * THE SKIP IS THE SAFETY. `SetProductAttributesAction` replaces the whole set
     * — correct for a form submit, destructive for a seeder — so a product a
     * human has since curated is left exactly as it is.
     *
     * @param array<string, Attribute> $attributes
     * @param array<string, string> $values attribute code => AttributeValue value, or a raw string
     */
    private function applyValues(Product $product, array $attributes, array $values): void
    {
        if ($product->descriptiveAttributes()->exists()) {
            return;
        }

        $assignments = [];

        foreach ($values as $code => $value) {
            $attribute = $attributes[$code] ?? null;

            if ($attribute === null) {
                continue;
            }

            if ($attribute->type->usesPredefinedValues()) {
                $option = $attribute->values->firstWhere('value', $value);

                // A select value that is not one of the attribute's own options
                // is a typo in this file, not something to store as free text.
                if ($option === null) {
                    continue;
                }

                $assignments[] = new ProductAttributeValueDTO(
                    attributeUuid: $attribute->uuid,
                    valueUuid: $option->uuid,
                );

                continue;
            }

            $assignments[] = new ProductAttributeValueDTO(
                attributeUuid: $attribute->uuid,
                value: $value,
            );
        }

        if ($assignments === []) {
            return;
        }

        app(SetProductAttributesAction::class)->run($product, $assignments);
    }
}
