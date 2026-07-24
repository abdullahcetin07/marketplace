<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Store\Domain\DTOs\UpdateStoreLocalizationDTO;
use App\Modules\Store\Domain\Models\Store;

/**
 * Set a store's default language, currency and timezone. Authorised by
 * `store.manage` via StorePolicy. The referenced rows must be active Localization
 * records — validated here, the trust boundary, before the action writes them.
 */
final class UpdateStoreLocalizationRequest extends BaseRequest
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
            'default_language_id' => ['sometimes', 'integer', 'exists:languages,id'],
            'default_currency_id' => ['sometimes', 'integer', 'exists:currencies,id'],
            'timezone_id' => ['sometimes', 'nullable', 'integer', 'exists:timezones,id'],
        ];
    }

    public function toDto(): UpdateStoreLocalizationDTO
    {
        $fields = ['default_language_id', 'default_currency_id', 'timezone_id'];

        return new UpdateStoreLocalizationDTO(
            defaultLanguageId: $this->has('default_language_id') ? $this->integer('default_language_id') : null,
            defaultCurrencyId: $this->has('default_currency_id') ? $this->integer('default_currency_id') : null,
            timezoneId: $this->input('timezone_id') !== null ? $this->integer('timezone_id') : null,
            present: array_values(array_intersect($fields, array_keys($this->all()))),
        );
    }
}
