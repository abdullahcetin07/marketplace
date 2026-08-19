<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A brand as the storefront's brand list and brand page see it (ADR-059).
 *
 * NOTHING ABOUT WHAT A BRAND'S PRODUCTS COST, for the same reason the product
 * payloads carry no price (ADR-037): a brand is catalogue content shared by every
 * seller, and a price on it would have to belong to one of them.
 *
 * `product_count` IS OF SELLABLE PRODUCTS, so the number and the listing behind it
 * agree — see `PublicTaxonomyBrowse`.
 */
final class PublicBrandResource extends JsonResource
{
    /**
     * @param array<string, mixed> $brand
     */
    public function __construct(private readonly array $brand)
    {
        parent::__construct($brand);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->brand['uuid'],
            'name' => $this->brand['name'],
            'slug' => $this->brand['slug'],
            // Null when nobody uploaded one — a client renders the name rather
            // than a broken image.
            'logo' => $this->brand['logo_url'],
            'product_count' => $this->brand['product_count'],
            /*
            | NULL-TOLERANT because two producers build this shape — the list and
            | the single-brand read. A missing key here used to be a 500 on a page
            | that has nothing to do with sitemaps.
            */
            'updated_at' => $this->brand['updated_at'] ?? null,
        ];
    }
}
