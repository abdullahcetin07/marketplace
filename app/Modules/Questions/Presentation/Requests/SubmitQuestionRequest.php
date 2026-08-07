<?php

declare(strict_types=1);

namespace App\Modules\Questions\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Shared\Enums\UserType;

/**
 * "Satıcıya Sor" — what the shopper's form posts (ADR-070).
 *
 * **TWO FIELDS, AND THE MISSING THIRD IS THE SECURITY MODEL.** There is no
 * `seller`, no `store` and no `offer`: the target is read from the buy box inside
 * `AskQuestionAction` and snapshotted there, so a hostile question cannot be
 * aimed at a merchant who never sold the thing. A field here would be a field
 * somebody could set.
 *
 * CUSTOMERS ONLY. `BaseRequest::authorize()` defaults to FALSE (CLAUDE.md), so
 * this is a deliberate opening — and being a signed-in customer is the WHOLE bar
 * (ADR-070). There is no purchase to check, which is this module's sharpest
 * difference from Reviews.
 *
 * **`min:5` IS THE ONLY QUALITY BAR AND IT IS INTENTIONALLY LOW.** "Kaça?" is a
 * real question; the minimum exists to stop an empty or one-character submission
 * reaching a merchant's queue, not to judge what a shopper wants to know.
 */
final class SubmitQuestionRequest extends BaseRequest
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
            // A slug or a uuid, resolved through `CatalogBrowseContract` in the
            // controller before anything touches a column (ADR-059).
            'product' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    public function productIdOrSlug(): string
    {
        return (string) $this->validated('product');
    }

    public function body(): string
    {
        return (string) $this->validated('body');
    }
}
