<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Store\Domain\DTOs\UpdateStoreDTO;
use App\Modules\Store\Domain\Models\Store;

/**
 * Edit a store's core identity (name). Authorised by `store.manage` on the
 * store's organization, via StorePolicy — never re-checked in the controller.
 */
final class UpdateStoreRequest extends BaseRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }

    public function toDto(): UpdateStoreDTO
    {
        $present = array_values(array_intersect(['name'], array_keys($this->all())));

        return new UpdateStoreDTO(name: $this->input('name'), present: $present);
    }
}
