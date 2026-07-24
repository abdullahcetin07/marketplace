<?php

declare(strict_types=1);

namespace App\Modules\Store\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Store\Domain\DTOs\UpdateStoreSettingsDTO;
use App\Modules\Store\Domain\Models\Store;
use App\Modules\Store\Domain\Models\StoreSettings;

/**
 * Update a storefront's operational settings (§2.2).
 *
 * PATCH: only present fields are written. The settings row is seeded at creation;
 * `firstOrCreate` keeps the action safe if a store predates that seeding. The
 * change is audited by StoreSettings' Auditable trait.
 */
final class UpdateStoreSettingsAction extends BaseAction
{
    public function handle(mixed ...$arguments): StoreSettings
    {
        /** @var Store $store */
        $store = $arguments[0];
        /** @var UpdateStoreSettingsDTO $data */
        $data = $arguments[1];

        $settings = $store->settings()->firstOrCreate([]);

        $changes = [];

        if ($data->has('announcement')) {
            $changes['announcement'] = $data->announcement;
        }

        if ($data->has('order_note_enabled')) {
            $changes['order_note_enabled'] = $data->orderNoteEnabled;
        }

        if ($data->has('weight_unit')) {
            $changes['weight_unit'] = $data->weightUnit;
        }

        if ($data->has('dimension_unit')) {
            $changes['dimension_unit'] = $data->dimensionUnit;
        }

        if ($data->has('metadata')) {
            $changes['metadata'] = $data->metadata;
        }

        if ($changes !== []) {
            $settings->update($changes);
        }

        return $settings->refresh();
    }
}
