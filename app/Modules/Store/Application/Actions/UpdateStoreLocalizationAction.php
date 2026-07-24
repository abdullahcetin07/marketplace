<?php

declare(strict_types=1);

namespace App\Modules\Store\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Store\Domain\DTOs\UpdateStoreLocalizationDTO;
use App\Modules\Store\Domain\Models\Store;

/**
 * Set a storefront's default language, currency and timezone (§6).
 *
 * These are columns on the Store itself, so the change is audited by the Store's
 * Auditable trait. The referenced Localization rows are validated at the request
 * layer; this action writes present ids. PATCH via the DTO's present list.
 */
final class UpdateStoreLocalizationAction extends BaseAction
{
    public function handle(mixed ...$arguments): Store
    {
        /** @var Store $store */
        $store = $arguments[0];
        /** @var UpdateStoreLocalizationDTO $data */
        $data = $arguments[1];

        $changes = [];

        if ($data->has('default_language_id')) {
            $changes['default_language_id'] = $data->defaultLanguageId;
        }

        if ($data->has('default_currency_id')) {
            $changes['default_currency_id'] = $data->defaultCurrencyId;
        }

        if ($data->has('timezone_id')) {
            $changes['timezone_id'] = $data->timezoneId;
        }

        if ($changes !== []) {
            $store->update($changes);
        }

        return $store->refresh();
    }
}
