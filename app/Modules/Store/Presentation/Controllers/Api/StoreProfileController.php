<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation\Controllers\Api;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Store\Application\Actions\UpdateStoreBrandingAction;
use App\Modules\Store\Application\Actions\UpdateStoreContactAction;
use App\Modules\Store\Application\Actions\UpdateStoreLocalizationAction;
use App\Modules\Store\Application\Actions\UpdateStoreSeoAction;
use App\Modules\Store\Application\Actions\UpdateStoreSettingsAction;
use App\Modules\Store\Domain\Models\Store;
use App\Modules\Store\Presentation\Requests\UpdateStoreBrandingRequest;
use App\Modules\Store\Presentation\Requests\UpdateStoreContactRequest;
use App\Modules\Store\Presentation\Requests\UpdateStoreLocalizationRequest;
use App\Modules\Store\Presentation\Requests\UpdateStoreSeoRequest;
use App\Modules\Store\Presentation\Requests\UpdateStoreSettingsRequest;
use App\Modules\Store\Presentation\Resources\StoreResource;
use Illuminate\Http\JsonResponse;

/**
 * The seller-facing storefront-customization surface (§2.2–2.6, §6).
 *
 * Split from StoreController to keep constructor dependencies within bounds.
 * Every method authorises through its FormRequest (`store.manage` via
 * StorePolicy) and delegates to one action; the store is returned so the client
 * has the current core state.
 */
final class StoreProfileController extends BaseController
{
    private const WITH = ['defaultLanguage', 'defaultCurrency', 'timezone'];

    public function __construct(
        private readonly UpdateStoreSettingsAction $settings,
        private readonly UpdateStoreBrandingAction $branding,
        private readonly UpdateStoreSeoAction $seo,
        private readonly UpdateStoreContactAction $contact,
        private readonly UpdateStoreLocalizationAction $localization,
    ) {}

    public function updateSettings(UpdateStoreSettingsRequest $request, Store $store): JsonResponse
    {
        $this->settings->run($store, $request->toDto());

        return $this->store($store);
    }

    public function updateBranding(UpdateStoreBrandingRequest $request, Store $store): JsonResponse
    {
        $this->branding->run($store, $request->toDto());

        return $this->store($store);
    }

    public function updateSeo(UpdateStoreSeoRequest $request, Store $store): JsonResponse
    {
        $this->seo->run($store, $request->toDto());

        return $this->store($store);
    }

    public function updateContact(UpdateStoreContactRequest $request, Store $store): JsonResponse
    {
        $this->contact->run($store, $request->toDto());

        return $this->store($store);
    }

    public function updateLocalization(UpdateStoreLocalizationRequest $request, Store $store): JsonResponse
    {
        $this->localization->run($store, $request->toDto());

        return $this->store($store->refresh());
    }

    private function store(Store $store): JsonResponse
    {
        return $this->ok(new StoreResource($store->load(self::WITH)));
    }
}
