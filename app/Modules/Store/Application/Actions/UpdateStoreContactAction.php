<?php

declare(strict_types=1);

namespace App\Modules\Store\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Store\Domain\DTOs\UpdateStoreContactDTO;
use App\Modules\Store\Domain\Models\Store;
use App\Modules\Store\Domain\Models\StoreContact;

/**
 * Update a storefront's public contact details (§2.6). PATCH via present list.
 */
final class UpdateStoreContactAction extends BaseAction
{
    public function handle(mixed ...$arguments): StoreContact
    {
        /** @var Store $store */
        $store = $arguments[0];
        /** @var UpdateStoreContactDTO $data */
        $data = $arguments[1];

        $contact = $store->contact()->firstOrCreate([]);

        $changes = [];

        if ($data->has('public_email')) {
            $changes['public_email'] = $data->publicEmail;
        }

        if ($data->has('public_phone')) {
            $changes['public_phone'] = $data->publicPhone;
        }

        if ($data->has('address')) {
            $changes['address'] = $data->address;
        }

        if ($data->has('support_hours')) {
            $changes['support_hours'] = $data->supportHours;
        }

        if ($changes !== []) {
            $contact->update($changes);
        }

        return $contact->refresh();
    }
}
