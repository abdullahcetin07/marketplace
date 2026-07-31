<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One product as a buyer sees it in a listing (ADR-058, Storefront.md §1.1).
 *
 * A STRICT ALLOW-LIST, the discipline every public surface on this platform keeps
 * (ADR-034). Internal ids, moderation state, the proposing company, the GTIN and
 * every seller-facing field are absent by construction — the rows this formats
 * came from a query that never selected them.
 *
 * THERE IS NO PRICE HERE, and that absence is the architecture rather than an
 * omission (ADR-037). The Catalog has no price to render; the storefront overlays
 * it from `POST /offers/prices` in one round trip for the whole page. A price
 * field on this payload would mean a price column in the Catalog, which is the one
 * thing that would stop a shared catalogue working.
 *
 * `id` IS THE UUID (non-negotiable #7). The slug rides along because a storefront
 * URL should read `/urun/pamuklu-tisort` rather than 36 hex characters — but the
 * uuid is what the price call and the buy-box call are keyed on.
 */
final class PublicProductCardResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $card
     */
    public function __construct(private readonly array $card)
    {
        parent::__construct($card);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->card['uuid'],
            'slug' => $this->card['slug'],
            'title' => $this->card['title'],
            // Null when the seller uploaded nothing: a client renders a
            // placeholder rather than a broken image.
            'image' => $this->card['primary_image_url'],
            'category' => [
                'id' => $this->card['category']['uuid'],
                'name' => $this->card['category']['name'],
            ],
            'brand' => $this->card['brand'] === null ? null : [
                'id' => $this->card['brand']['uuid'],
                'name' => $this->card['brand']['name'],
            ],
        ];
    }
}
