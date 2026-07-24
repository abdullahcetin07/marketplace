<?php

declare(strict_types=1);

use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Localization\Domain\Models\Language;
use App\Modules\Organization\Domain\Events\StoreOpeningApproved;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\StoreOpeningRequest;
use App\Modules\Store\Application\Actions\UpdateStoreBrandingAction;
use App\Modules\Store\Application\Actions\UpdateStoreContactAction;
use App\Modules\Store\Application\Actions\UpdateStoreLocalizationAction;
use App\Modules\Store\Application\Actions\UpdateStoreSeoAction;
use App\Modules\Store\Application\Actions\UpdateStoreSettingsAction;
use App\Modules\Store\Domain\DTOs\UpdateStoreBrandingDTO;
use App\Modules\Store\Domain\DTOs\UpdateStoreContactDTO;
use App\Modules\Store\Domain\DTOs\UpdateStoreLocalizationDTO;
use App\Modules\Store\Domain\DTOs\UpdateStoreSeoDTO;
use App\Modules\Store\Domain\DTOs\UpdateStoreSettingsDTO;
use App\Modules\Store\Domain\Models\Store;

/*
|--------------------------------------------------------------------------
| Store — storefront customization (§2.2–2.6, §6)
|--------------------------------------------------------------------------
|
| Settings/branding/seo/contact are seeded empty at creation; PATCH updates
| touch only the fields sent. Localization writes the Store's own locale columns.
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

it('seeds an empty storefront profile when the store is created', function (): void {
    $org = Organization::factory()->approved()->create();
    $request = StoreOpeningRequest::factory()->for($org)->approved()->create();

    StoreOpeningApproved::dispatch($org->getKey(), $org->uuid, $request->uuid, $request->requested_by, $request->store_name, $request->slug);

    $store = Store::query()->where('opening_request_uuid', $request->uuid)->firstOrFail();

    expect($store->settings()->exists())->toBeTrue()
        ->and($store->branding()->exists())->toBeTrue()
        ->and($store->seo()->exists())->toBeTrue()
        ->and($store->contact()->exists())->toBeTrue()
        // The SEO row carries its default robots directive.
        ->and($store->seo()->first()->robots)->toBe('index,follow');
});

it('patches settings without clearing omitted fields', function (): void {
    $store = Store::factory()->create();
    $store->settings()->create(['announcement' => 'Summer sale']);

    UpdateStoreSettingsAction::make()->run($store, new UpdateStoreSettingsDTO(
        orderNoteEnabled: true,
        present: ['order_note_enabled'],
    ));

    $settings = $store->settings()->first();
    expect($settings->order_note_enabled)->toBeTrue()
        // Omitted from `present` — must survive the PATCH.
        ->and($settings->announcement)->toBe('Summer sale');
});

it('updates seo, contact and branding', function (): void {
    $store = Store::factory()->create();

    UpdateStoreSeoAction::make()->run($store, new UpdateStoreSeoDTO(
        metaTitle: 'Acme Goods',
        robots: 'noindex,nofollow',
        present: ['meta_title', 'robots'],
    ));
    UpdateStoreContactAction::make()->run($store, new UpdateStoreContactDTO(
        publicEmail: 'hello@acme.test',
        present: ['public_email'],
    ));
    UpdateStoreBrandingAction::make()->run($store, new UpdateStoreBrandingDTO(
        primaryColor: '#112233',
        present: ['primary_color'],
    ));

    expect($store->seo()->first()->meta_title)->toBe('Acme Goods')
        ->and($store->seo()->first()->robots)->toBe('noindex,nofollow')
        ->and($store->contact()->first()->public_email)->toBe('hello@acme.test')
        ->and($store->branding()->first()->primary_color)->toBe('#112233');
});

it('sets the storefront default locale on the store', function (): void {
    $store = Store::factory()->create();
    $language = (int) Language::query()->value('id');
    $currency = (int) Currency::query()->value('id');

    UpdateStoreLocalizationAction::make()->run($store, new UpdateStoreLocalizationDTO(
        defaultLanguageId: $language,
        defaultCurrencyId: $currency,
        present: ['default_language_id', 'default_currency_id'],
    ));

    expect((int) $store->refresh()->default_language_id)->toBe($language)
        ->and((int) $store->default_currency_id)->toBe($currency);
});
