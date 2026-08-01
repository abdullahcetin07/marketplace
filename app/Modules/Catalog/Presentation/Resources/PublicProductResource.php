<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Resources;

use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The product page's content (ADR-058, Storefront.md §1.1).
 *
 * WHAT THE THING IS, AND NOTHING ABOUT WHAT IT COSTS. Price and availability are
 * a second call — `GET /products/{uuid}/offers`, which already exists — and the
 * separation is ADR-037 made visible: one catalogue entry, many sellers, so the
 * content is shared and the commerce is per merchant. A price on this payload
 * would have to belong to somebody.
 *
 * VARIANTS ARE HERE, PRICES ARE NOT, and that is what makes the page work: the
 * buyer picks "Kırmızı / M" from this response and the buy box tells them what
 * that variant costs from whom.
 *
 * A STRICT ALLOW-LIST (ADR-034). No internal ids, no moderation state, no
 * proposing company, no seller-facing provenance — a draft's existence must not
 * leak through a public payload any more than through a 404.
 *
 * THE GTIN IS ON THE LIST, and it is the one field this surface deliberately
 * shows that the card surface does not (owner-approved, 2026-08-01). The barcode
 * is PRINTED ON THE BOX a shopper is holding, so withholding it protects nothing
 * — anyone who wants a competitor's GTIN reads it off the product. What it does
 * do is let a buyer confirm they are looking at the right item, which is the
 * whole reason "Barkod" appears on the design's spec table.
 *
 * DETAIL ONLY, and the asymmetry is the point: the card surface is the LISTING,
 * where the GTIN would be a machine-readable index of the whole catalogue handed
 * out a page at a time. One product's barcode is a fact about that product;
 * every product's barcode is a catalogue export.
 *
 * THE CATEGORY IS A PATH, not a single name: a shopper needs the breadcrumb, and
 * building it client-side would mean exposing the tree.
 *
 * @mixin Product
 */
final class PublicProductResource extends JsonResource
{
    /**
     * @param  array<int, array{uuid: string, name: string}>  $categoryPath
     */
    public function __construct(
        Product $product,
        private readonly array $categoryPath = [],
    ) {
        parent::__construct($product);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'slug' => $this->slug,
            'title' => $this->localized('title'),
            'description' => $this->localized('description'),
            // Null for most of the catalogue: it is optional at authoring, and a
            // hand-made or unbranded product legitimately has none.
            'gtin' => $this->gtin,

            'images' => $this->gallery(),

            'category' => [
                'id' => $this->category->uuid,
                'name' => $this->category->localized('name'),
                // Root first — the breadcrumb a shopper reads left to right.
                'path' => array_map(static fn (array $node): array => [
                    'id' => $node['uuid'],
                    'name' => $node['name'],
                ], $this->categoryPath),
            ],

            'brand' => $this->brand === null ? null : [
                'id' => $this->brand->uuid,
                'name' => $this->brand->name,
            ],

            /*
            | The DESCRIPTIVE attributes — "100% pamuk", "Made in Türkiye". The
            | variant-defining ones are not listed here: they are the axes the
            | variants below are made of, and repeating them as facts about the
            | product would say a t-shirt "is" red when only one variant is.
            */
            'attributes' => $this->descriptiveValues(),

            'variants' => $this->variants
                ->map(static fn (ProductVariant $variant): array => [
                    'id' => $variant->uuid,
                    // The label a human picks by ("Kırmızı / M"), not the
                    // combination key the database sorts on.
                    'label' => $variant->combinationLabel(),
                    'is_default' => $variant->is_default,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Every gallery image, largest-usable conversion.
     *
     * @return array<int, string>
     */
    private function gallery(): array
    {
        return $this->getMedia('images')
            ->map(static fn ($media): string => $media->getUrl('preview'))
            ->values()
            ->all();
    }

    /**
     * The product's descriptive attribute values, as name/value pairs.
     *
     * NOT NAMED `attributes()`: `JsonResource` already has one, and overriding it
     * would break the resource in a way that only shows up at render time. The
     * same collision `Product::descriptiveAttributes()` was renamed to avoid.
     *
     * The pivot carries EITHER a free string (Text/Number/Boolean attributes) OR a
     * reference to an enumerated `AttributeValue` (Select ones) — one row either
     * way (Catalog §2.4), so both have to be read here.
     *
     * @return array<int, array{name: string, value: string}>
     */
    private function descriptiveValues(): array
    {
        $attributes = [];

        foreach ($this->descriptiveAttributes as $attribute) {
            $pivot = $attribute->getRelationValue('pivot');
            $value = $pivot?->getAttribute('value');

            if (! is_string($value) || $value === '') {
                $valueId = $pivot?->getAttribute('attribute_value_id');
                $value = $valueId === null
                    ? null
                    : $attribute->values->firstWhere('id', $valueId)?->localized('label');
            }

            if (is_string($value) && $value !== '') {
                $attributes[] = [
                    'name' => $attribute->localized('name'),
                    'value' => $value,
                ];
            }
        }

        return $attributes;
    }
}
