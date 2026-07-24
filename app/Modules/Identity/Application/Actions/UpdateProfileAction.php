<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Models\User;
use App\Modules\Identity\Domain\DTOs\UpdateProfileDTO;
use App\Modules\Localization\Domain\Contracts\CountryRepositoryContract;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Localization\Domain\Contracts\LanguageRepositoryContract;
use App\Modules\Localization\Domain\Contracts\TimezoneRepositoryContract;

/**
 * Update a user's own profile.
 *
 * PATCH SEMANTICS: only fields the request actually carried are touched. The
 * DTO's `present` list is what distinguishes "not supplied" (leave alone) from
 * "supplied as null" (clear). Building the update from `present` rather than
 * from non-null values is the difference between a PATCH and a PUT.
 *
 * Locale codes resolve to ids through the Localization repositories (§26 —
 * Localization is the one permitted cross-module dependency). An unknown code
 * was already rejected by validation, so a null result here means the field was
 * deliberately cleared.
 *
 * Email is not updatable through this action. @see spec §5.4.
 */
final class UpdateProfileAction extends BaseAction
{
    public function __construct(
        private readonly LanguageRepositoryContract $languages,
        private readonly CountryRepositoryContract $countries,
        private readonly CurrencyRepositoryContract $currencies,
        private readonly TimezoneRepositoryContract $timezones,
    ) {}

    public function handle(mixed ...$arguments): User
    {
        /** @var User $user */
        $user = $arguments[0];
        /** @var UpdateProfileDTO $data */
        $data = $arguments[1];

        $changes = [];

        // Scalars — present means write, whatever the value.
        if ($data->has('first_name')) {
            $changes['first_name'] = $data->firstName;
        }

        if ($data->has('last_name')) {
            $changes['last_name'] = $data->lastName;
        }

        if ($data->has('phone')) {
            $changes['phone'] = $data->phone;
        }

        // Locale relations — resolve the code to an id, or null to clear.
        if ($data->has('language_code')) {
            $changes['language_id'] = $data->languageCode === null
                ? null
                : $this->languages->findByCode($data->languageCode)?->getKey();
        }

        if ($data->has('country_code')) {
            $changes['country_id'] = $data->countryCode === null
                ? null
                : $this->countries->findByIso2($data->countryCode)?->getKey();
        }

        if ($data->has('currency_code')) {
            $changes['currency_id'] = $data->currencyCode === null
                ? null
                : $this->currencies->findByCode($data->currencyCode)?->getKey();
        }

        if ($data->has('timezone_name')) {
            $changes['timezone_id'] = $data->timezoneName === null
                ? null
                : $this->timezones->findByName($data->timezoneName)?->getKey();
        }

        if ($changes !== []) {
            // The UserObserver dispatches UserUpdated with the changed attribute
            // NAMES — Activity records it, no call from here.
            $user->update($changes);
        }

        return $user->refresh();
    }
}
