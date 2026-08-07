<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;

/**
 * "Şu kırk ürünün yıldızları" (ADR-069).
 *
 * PUBLIC, like the listing page it feeds. `authorize()` returns true explicitly
 * because `BaseRequest` defaults to FALSE (CLAUDE.md) — an anonymous grid of
 * product cards is exactly the case that default exists to make somebody think
 * about.
 *
 * **CAPPED AT 100, AND THE CAP IS THE POINT OF VALIDATING AT ALL.** The endpoint
 * exists so one call prices a whole grid instead of forty; without a ceiling it
 * is also a way to ask the platform to group over every product it has, from an
 * unauthenticated route.
 */
final class BatchRatingsRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_ids' => ['required', 'array', 'max:100'],
            // `uuid` FIRST LINE OF THE CAST-TRAP GUARD (ADR-059): these reach a
            // native uuid column, where a non-uuid string is SQLSTATE[22P02].
            'product_ids.*' => ['uuid'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function productUuids(): array
    {
        /** @var array<int, string> $ids */
        $ids = $this->validated('product_ids') ?? [];

        return array_values(array_unique($ids));
    }
}
