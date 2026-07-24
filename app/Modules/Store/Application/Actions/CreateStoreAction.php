<?php

declare(strict_types=1);

namespace App\Modules\Store\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Localization\Domain\Contracts\LanguageRepositoryContract;
use App\Modules\Localization\Domain\Contracts\TimezoneRepositoryContract;
use App\Modules\Organization\Domain\Events\StoreOpeningApproved;
use App\Modules\Store\Domain\Contracts\StoreNumberGeneratorContract;
use App\Modules\Store\Domain\Contracts\StoreSlugGeneratorContract;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Events\StoreCreated;
use App\Modules\Store\Domain\Models\Store;

/**
 * Turns an approved Store Opening Request into a storefront (ADR-032).
 *
 * THE SOLE CREATION PATH. No seller action reaches this — it runs only from the
 * `StoreOpeningApproved` listener. The store is born in Draft with the PLATFORM
 * default locale (§4.3); the seller configures language/currency/timezone later.
 * Frozen Organization is not consulted for locale and the event is not extended.
 *
 * Idempotency (ADR-032) is enforced in two places: the listener pre-checks the
 * request UUID, and `stores.opening_request_uuid` is UNIQUE so a concurrent
 * double-delivery loses the race at the database. This action does the
 * pre-committed work; `after()` announces `StoreCreated` once the row is
 * durable, so no consumer reacts to a store that rolled back.
 */
final class CreateStoreAction extends BaseAction
{
    public function __construct(
        private readonly LanguageRepositoryContract $languages,
        private readonly CurrencyRepositoryContract $currencies,
        private readonly TimezoneRepositoryContract $timezones,
        private readonly StoreSlugGeneratorContract $slugs,
        private readonly StoreNumberGeneratorContract $numbers,
    ) {}

    public function handle(mixed ...$arguments): Store
    {
        /** @var StoreOpeningApproved $event */
        $event = $arguments[0];

        $language = $this->languages->default();
        $currency = $this->currencies->default();
        $timezone = $this->timezones->default();

        $store = Store::create([
            'organization_id' => $event->organizationId,
            'organization_uuid' => $event->organizationUuid,
            'opening_request_uuid' => $event->requestUuid,
            'name' => $event->storeName,
            // Slug + number policy lives behind contracts, never in the
            // aggregate — so a future numbering/reserved-slug scheme swaps the
            // generator without touching Store (Store.md §Slug and Store Number).
            'slug' => $this->slugs->generate($event->slug),
            'store_number' => $this->numbers->generate(),
            'status' => StoreStatus::Draft,
            'default_language_id' => $language->getKey(),
            'default_currency_id' => $currency->getKey(),
            'timezone_id' => $timezone?->getKey(),
        ]);

        // Seed the empty storefront profile so the seller edits existing rows
        // rather than the module special-casing "first save" (§4.1). Each is a
        // 1:1 companion with its own defaults.
        $store->settings()->create([]);
        $store->branding()->create([]);
        $store->seo()->create([]);
        $store->contact()->create([]);

        return $store;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Store $result */
        StoreCreated::dispatch(
            $result->getKey(),
            $result->uuid,
            $result->organization_id,
            $result->organization_uuid,
            $result->opening_request_uuid,
            $result->name,
            $result->slug,
        );
    }
}
