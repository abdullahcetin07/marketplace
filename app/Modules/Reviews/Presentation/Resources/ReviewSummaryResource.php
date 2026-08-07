<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Presentation\Resources;

/**
 * A product's rating, as the star block above the review list (ADR-069).
 *
 * **NOT A `BaseResource`, AND THE REASON IS THAT IT WRAPS NO MODEL.** Every other
 * resource on the platform presents a row; this presents an ARITHMETIC RESULT
 * computed across many rows, with no `uuid` for `publicId()` to return and no
 * timestamps. Extending the model resource base would have meant inheriting
 * helpers that cannot work here and pretending an array is an Eloquent record.
 *
 * IT IS STILL A RESOURCE BY JOB: the one place that decides what the summary
 * looks like on the wire, so the product page and any later surface cannot
 * disagree about the shape.
 *
 * THE AVERAGE ARRIVES AS A STRING AND LEAVES AS ONE. It is not money and the
 * minor-units rule does not apply — but most clients parse a JSON number as a
 * float, and "4.3" is not representable as one. @see `ReviewRepository::average()`.
 */
final readonly class ReviewSummaryResource
{
    /**
     * @param array{average: string, count: int, distribution: array<int, int>, with_images_count: int, sellers: array<int, array{store_uuid: string, count: int}>} $summary
     * @param array<string, array{name: string, city: string|null, slug: string}> $stores keyed by store uuid
     */
    public function __construct(
        private array $summary,
        private array $stores = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'average' => $this->summary['average'],
            'count' => $this->summary['count'],
            // Always 5..1, always filled: a bar chart missing its empty buckets
            // is a bar chart every client repairs slightly differently.
            'distribution' => $this->summary['distribution'],
            'with_images_count' => $this->summary['with_images_count'],
            'sellers' => array_map(
                fn (array $seller): array => [
                    'id' => $seller['store_uuid'],
                    'name' => $this->stores[$seller['store_uuid']]['name'] ?? null,
                    'count' => $seller['count'],
                ],
                $this->summary['sellers'],
            ),
            /*
            | THE SELLER BREAKDOWN IS WHAT MAKES THE FILTER USABLE. "Bu satıcıdan
            | alanlar ne demiş" needs to be offered before it can be chosen, and
            | a shopper cannot type a store uuid — so the counts double as the
            | filter's own option list.
            */
        ];
    }
}
