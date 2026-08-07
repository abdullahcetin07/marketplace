<?php

declare(strict_types=1);

namespace App\Modules\Questions\Presentation\Resources;

use App\Core\Presentation\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * One answered question, as a stranger reads it (ADR-070).
 *
 * **ABSENT, EACH DELIBERATELY:** `customer_id` and `customer_uuid` (a uuid here
 * would let anyone correlate one shopper's questions across the catalogue — a
 * browsing history rebuilt from public data), `answered_by` (the shopper sees the
 * SHOP's answer, not which colleague typed it), and everything about the hide —
 * a question that reaches this resource is not hidden, and naming the mechanism
 * would advertise it.
 *
 * `asker_name` IS ALREADY MASKED IN THE COLUMN, exactly as a review's author is,
 * so a future surface cannot leak a full name by forgetting a formatter.
 *
 * **BOTH DATES ARE HERE AND THAT IS NOT PADDING.** "Sorulma" and "cevaplanma" are
 * the pair a shopper judges a merchant by: an answer three months after the
 * question says something a single timestamp cannot.
 *
 * @extends BaseResource<\App\Modules\Questions\Domain\Models\Question>
 */
final class PublicQuestionResource extends BaseResource
{
    /**
     * @param \App\Modules\Questions\Domain\Models\Question $resource
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
            'asker_name' => $this->resource->asker_name,
            'body' => $this->resource->body,
            'answer_body' => $this->resource->answer_body,
            'seller' => [
                'id' => $this->resource->store_uuid,
                // Null when the shop is no longer live — the profile read is
                // live-only. The Q&A still shows: it was true when it was
                // written, and hiding it would let a merchant escape their
                // answers by being suspended.
                'name' => $store['name'] ?? null,
                'slug' => $store['slug'] ?? null,
            ],
            'asked_at' => $this->resource->created_at?->toIso8601String(),
            'answered_at' => $this->resource->answered_at?->toIso8601String(),
        ];
    }
}
