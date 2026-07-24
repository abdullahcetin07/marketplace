<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Store\Domain\DTOs\UpdateStoreSettingsDTO;
use App\Modules\Store\Domain\Models\Store;

/**
 * Edit a store's operational settings. Authorised by `store.manage` via StorePolicy.
 */
final class UpdateStoreSettingsRequest extends BaseRequest
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
            'announcement' => ['sometimes', 'nullable', 'string', 'max:500'],
            'order_note_enabled' => ['sometimes', 'boolean'],
            'weight_unit' => ['sometimes', 'string', 'max:8'],
            'dimension_unit' => ['sometimes', 'string', 'max:8'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function toDto(): UpdateStoreSettingsDTO
    {
        $fields = ['announcement', 'order_note_enabled', 'weight_unit', 'dimension_unit', 'metadata'];

        return new UpdateStoreSettingsDTO(
            announcement: $this->input('announcement'),
            orderNoteEnabled: $this->has('order_note_enabled') ? $this->boolean('order_note_enabled') : null,
            weightUnit: $this->input('weight_unit'),
            dimensionUnit: $this->input('dimension_unit'),
            metadata: $this->input('metadata'),
            present: array_values(array_intersect($fields, array_keys($this->all()))),
        );
    }
}
