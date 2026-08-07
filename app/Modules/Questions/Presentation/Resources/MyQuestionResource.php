<?php

declare(strict_types=1);

namespace App\Modules\Questions\Presentation\Resources;

use App\Core\Presentation\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A shopper's own question, in "Sorularım" (Questions.md §8).
 *
 * **IT CARRIES THE STATUS AND THE PUBLIC RESOURCE DOES NOT**, which is the whole
 * difference between the two. A shopper must see that their question is still
 * waiting — otherwise it looks lost and they ask it again — and see the answer
 * the moment it arrives.
 *
 * **A HIDDEN QUESTION STILL APPEARS HERE, AND SAYS NOTHING ABOUT BEING HIDDEN.**
 * It is still their question and removing it would be the platform editing
 * somebody's history without telling them; but "an admin took this down" is a
 * conversation the platform has no process for, so the row is shown as whatever
 * its status is. The one deliberate silence in this resource.
 *
 * THE PRODUCT TITLE IS RESOLVED, NOT STORED. A question is not a receipt — the
 * product still exists and may have been renamed — so the current title is the
 * useful one, batched through `CatalogBrowseContract`.
 *
 * @extends BaseResource<\App\Modules\Questions\Domain\Models\Question>
 */
final class MyQuestionResource extends BaseResource
{
    /**
     * @param \App\Modules\Questions\Domain\Models\Question $resource
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
            // question stays, and the client renders what it has.
            'product_title' => $this->products[$this->resource->product_uuid]['title'] ?? null,
            'body' => $this->resource->body,
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            'answer_body' => $this->resource->answer_body,
            'seller' => [
                // The uuid alone: this list is about the shopper's own questions,
                // and naming the shop is the product page's job.
                'id' => $this->resource->store_uuid,
            ],
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'answered_at' => $this->resource->answered_at?->toIso8601String(),
        ];
    }
}
