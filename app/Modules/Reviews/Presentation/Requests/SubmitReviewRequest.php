<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Shared\Enums\UserType;

/**
 * "Değerlendir" — what the buyer's form posts (ADR-067).
 *
 * **IT VALIDATES SHAPE AND NOTHING ELSE.** Whether the line is theirs, delivered
 * and unreviewed is decided in `CreateReviewAction`, on the server, against the
 * Core Order port — this request could not answer any of those and must not look
 * like it did. The rules here stop a malformed payload reaching a native `uuid`
 * column (ADR-059); they are not the gate.
 *
 * CUSTOMERS ONLY. `BaseRequest::authorize()` defaults to FALSE (CLAUDE.md), so
 * this is a deliberate opening rather than an omission — and it is the only
 * actor type that can hold a delivered purchase.
 *
 * **THE RATING IS REQUIRED AND THE TEXT IS NOT** (Reviews.md §4). A star is the
 * thing every review must carry — it is what the average is made of — while
 * "beğendim" adds nothing a five-star does not already say. Photos are optional
 * for the same reason and capped at six, which is a form, not a gallery.
 */
final class SubmitReviewRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->actor()?->type === UserType::Customer;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
            | **THE PRODUCT IS REQUIRED, AND BUILD_REVIEWS.md §R9's SKETCH OMITS
            | IT** — a recorded deviation. The gate is keyed on (customer,
            | PRODUCT) by ADR-067's own design: `deliveredPurchaseLines()` cannot
            | be asked "which purchase is this line?" without one, and Reviews
            | may not read `order_lines` to find out. So the server literally
            | cannot locate the line without it.
            |
            | IT WEAKENS NOTHING. The product is not trusted — it only chooses
            | which of the buyer's delivered lines to check, and a wrong or
            | forged one simply yields no match and a refusal. And the storefront
            | is standing on the product page when it renders this form, so it
            | costs the client nothing.
            |
            | A slug or a uuid, resolved through `CatalogBrowseContract` in the
            | controller before anything touches a column (ADR-059).
            */
            'product' => ['required', 'string', 'max:255'],
            'order_line_uuid' => ['required', 'uuid'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body' => ['nullable', 'string', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:6'],
            /*
            | 8 MB, UNDER `HasMedia::maxUploadSize()`'s 10 MB backstop. Two
            | limits on purpose: this one rejects at the edge with a message the
            | buyer can act on, the storage-layer one catches anything that
            | reaches a model by another route.
            */
            'photos.*' => ['image', 'mimes:jpeg,png,webp,avif', 'max:8192'],
        ];
    }

    public function orderLineUuid(): string
    {
        return (string) $this->validated('order_line_uuid');
    }

    public function rating(): int
    {
        return (int) $this->validated('rating');
    }

    public function body(): ?string
    {
        return $this->validated('body');
    }
}
