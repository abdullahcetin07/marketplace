<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Presentation\Resources;

use App\Core\Presentation\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * One published review, as a stranger reads it (ADR-069).
 *
 * **THIS IS THE FIRST RESOURCE ON THE PLATFORM THAT PUBLISHES SOMETHING ONE USER
 * WROTE TO EVERY OTHER USER**, so what it leaves out matters more than what it
 * includes. Absent, deliberately, every one of them:
 *
 *   `customer_id` / `customer_uuid` — the author is a masked name and nothing
 *   else. A uuid here would let anyone correlate one person's reviews across the
 *   whole catalogue, which is a shopping history rebuilt from public data.
 *
 *   `order_line_uuid` — the purchase this review is bound to is the platform's
 *   proof, not the shopper's business, and exposing it would hand out a
 *   guessable handle to somebody's order.
 *
 *   `status`, `moderated_by`, `moderation_reason` — a published review is
 *   published; who approved it and what an internal note said are the
 *   moderator's record.
 *
 * `author_name` IS ALREADY MASKED IN THE COLUMN (Reviews.md §8) rather than
 * masked here, so a future surface cannot leak a full name by forgetting to call
 * a formatter.
 *
 * THE SELLER IS NAMED, NOT JUST IDENTIFIED. The name arrives batched from
 * `StoreQueryContract` — Reviews may not import Store — and is null when the shop
 * is no longer live, because that read is live-only. A review of a suspended
 * seller still shows: the opinion was true when it was written, and hiding it
 * would let a shop escape its reviews by being suspended.
 *
 * @extends BaseResource<\App\Modules\Reviews\Domain\Models\Review>
 */
final class PublicReviewResource extends BaseResource
{
    /**
     * @param \App\Modules\Reviews\Domain\Models\Review $resource
     * @param array<string, array{name: string, city: string|null, slug: string}> $stores keyed by store uuid
     */
    public function __construct($resource, private readonly array $stores = [])
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $store = $this->stores[$this->resource->store_uuid] ?? null;

        return [
            'id' => $this->publicId(),
            'rating' => $this->resource->rating,
            'body' => $this->resource->body,
            'author_name' => $this->resource->author_name,
            'seller' => [
                'id' => $this->resource->store_uuid,
                'name' => $store['name'] ?? null,
                // The slug the store page is addressed by, so a review card can
                // link to the shop the way a buy box does.
                'slug' => $store['slug'] ?? null,
            ],
            'variant_label' => $this->resource->variant_label ?? null,
            'images' => $this->images(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }

    /**
     * The three sizes the storefront actually renders.
     *
     * NOT `imageGallery()` VERBATIM: that carries the media uuid, the original
     * filename and an order column, none of which a review card uses and the
     * first two of which are more identifiers on a public surface than the page
     * needs.
     *
     * @return array<int, array<string, string>>
     */
    private function images(): array
    {
        if (! $this->resource->has_photos) {
            // The denormalised flag saves a media read on every row of a page
            // where most reviews have no photos at all.
            return [];
        }

        return array_map(
            static fn (array $image): array => [
                'thumb' => (string) $image['thumb'],
                'preview' => (string) $image['preview'],
                'large' => (string) $image['large'],
            ],
            $this->resource->imageGallery(),
        );
    }
}
