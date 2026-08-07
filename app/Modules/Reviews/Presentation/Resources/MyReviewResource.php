<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Presentation\Resources;

use App\Core\Presentation\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A buyer's own review, in "değerlendirmelerim" (Reviews.md §8).
 *
 * **IT CARRIES THE STATUS AND THE PUBLIC ONE DOES NOT**, which is the whole
 * difference between the two resources. A buyer must be able to see that their
 * review is waiting for approval — otherwise it looks lost and they write it
 * again — and that a rejected one was refused, so they stop waiting.
 *
 * **THE PRODUCT TITLE IS RESOLVED, NOT STORED.** A review holds a
 * `product_uuid` and no title: unlike an order line (ADR-053) there is nothing
 * to freeze, because a review is not a receipt of what was agreed — it is an
 * opinion about a product that still exists and may since have been renamed.
 * The current title is the useful one, and it arrives batched through
 * `CatalogBrowseContract` because Reviews may not import Catalog.
 *
 * NO MODERATION REASON. A rejected review shows as rejected and no further
 * (Reviews.md §6): telling somebody why their opinion was refused invites an
 * argument the platform has no process for.
 *
 * @extends BaseResource<\App\Modules\Reviews\Domain\Models\Review>
 */
final class MyReviewResource extends BaseResource
{
    /**
     * @param \App\Modules\Reviews\Domain\Models\Review $resource
     * @param array<string, array{uuid: string, title: string, brand: string|null, category: string}> $products keyed by product uuid
     */
    public function __construct($resource, private readonly array $products = [])
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->publicId(),
            'product_id' => $this->resource->product_uuid,
            // Null when the product has since been unpublished or removed — the
            // review stays, and the client renders what it has.
            'product_title' => $this->products[$this->resource->product_uuid]['title'] ?? null,
            'rating' => $this->resource->rating,
            'body' => $this->resource->body,
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            'images' => $this->resource->has_photos
                ? array_map(
                    static fn (array $image): array => [
                        'thumb' => (string) $image['thumb'],
                        'preview' => (string) $image['preview'],
                        'large' => (string) $image['large'],
                    ],
                    $this->resource->imageGallery(),
                )
                : [],
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
