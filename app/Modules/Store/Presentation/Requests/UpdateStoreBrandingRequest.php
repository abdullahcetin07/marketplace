<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Store\Domain\DTOs\UpdateStoreBrandingDTO;
use App\Modules\Store\Domain\Models\Store;

/**
 * Edit a store's theme fields. Authorised by `store.manage` via StorePolicy. The
 * logo/banner/favicon media are uploaded through the media endpoints, not here.
 */
final class UpdateStoreBrandingRequest extends BaseRequest
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
            'primary_color' => ['sometimes', 'nullable', 'string', 'max:9'],
            'accent_color' => ['sometimes', 'nullable', 'string', 'max:9'],
            'theme' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function toDto(): UpdateStoreBrandingDTO
    {
        $fields = ['primary_color', 'accent_color', 'theme'];

        return new UpdateStoreBrandingDTO(
            primaryColor: $this->input('primary_color'),
            accentColor: $this->input('accent_color'),
            theme: $this->input('theme'),
            present: array_values(array_intersect($fields, array_keys($this->all()))),
        );
    }
}
