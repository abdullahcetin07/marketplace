<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Store\Domain\DTOs\UpdateStoreSeoDTO;
use App\Modules\Store\Domain\Models\Store;

/**
 * Edit a store's SEO metadata. Authorised by `store.manage` via StorePolicy.
 */
final class UpdateStoreSeoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        $store = $this->route('store');

        return $store instanceof Store && $this->actor()?->can('update', $store) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'meta_keywords' => ['sometimes', 'nullable', 'array'],
            'meta_keywords.*' => ['string', 'max:64'],
            'canonical_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'robots' => ['sometimes', 'string', 'max:40'],
        ];
    }

    public function toDto(): UpdateStoreSeoDTO
    {
        $fields = ['meta_title', 'meta_description', 'meta_keywords', 'canonical_url', 'robots'];

        return new UpdateStoreSeoDTO(
            metaTitle: $this->input('meta_title'),
            metaDescription: $this->input('meta_description'),
            metaKeywords: $this->input('meta_keywords'),
            canonicalUrl: $this->input('canonical_url'),
            robots: $this->input('robots'),
            present: array_values(array_intersect($fields, array_keys($this->all()))),
        );
    }
}
