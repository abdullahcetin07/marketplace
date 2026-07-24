<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Store\Domain\DTOs\UpdateStoreContactDTO;
use App\Modules\Store\Domain\Models\Store;

/**
 * Edit a store's public contact details. Authorised by `store.manage` via StorePolicy.
 */
final class UpdateStoreContactRequest extends BaseRequest
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
            'public_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'public_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'address' => ['sometimes', 'nullable', 'array'],
            'support_hours' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function toDto(): UpdateStoreContactDTO
    {
        $fields = ['public_email', 'public_phone', 'address', 'support_hours'];

        return new UpdateStoreContactDTO(
            publicEmail: $this->input('public_email'),
            publicPhone: $this->input('public_phone'),
            address: $this->input('address'),
            supportHours: $this->input('support_hours'),
            present: array_values(array_intersect($fields, array_keys($this->all()))),
        );
    }
}
