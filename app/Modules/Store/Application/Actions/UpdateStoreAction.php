<?php

declare(strict_types=1);

namespace App\Modules\Store\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Store\Domain\DTOs\UpdateStoreDTO;
use App\Modules\Store\Domain\Models\Store;

/**
 * Update a store's core identity fields (name).
 *
 * Integration glue, not a new rule: it writes existing columns and the change is
 * audited by the Store's Auditable trait. PATCH semantics from the DTO.
 */
final class UpdateStoreAction extends BaseAction
{
    public function handle(mixed ...$arguments): Store
    {
        /** @var Store $store */
        $store = $arguments[0];
        /** @var UpdateStoreDTO $data */
        $data = $arguments[1];

        $changes = [];

        if ($data->has('name')) {
            $changes['name'] = $data->name;
        }

        if ($changes !== []) {
            $store->update($changes);
        }

        return $store->refresh();
    }
}
