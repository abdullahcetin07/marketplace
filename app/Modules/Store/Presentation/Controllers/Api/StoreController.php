<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation\Controllers\Api;

use App\Core\Domain\Contracts\OrganizationAuthorizationContract;
use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Store\Application\Actions\UpdateStoreAction;
use App\Modules\Store\Domain\Contracts\StoreRepositoryContract;
use App\Modules\Store\Domain\Models\Store;
use App\Modules\Store\Presentation\Requests\UpdateStoreRequest;
use App\Modules\Store\Presentation\Resources\StoreResource;
use Illuminate\Http\JsonResponse;

/**
 * The seller-facing store surface (ADR-030, ADR-033).
 *
 * THIN BY CONTRACT: it resolves a store (bound by UUID), lets StorePolicy /
 * the FormRequest authorise, calls one action, returns a resource. No queries,
 * no authorization logic. `index` is scoped through the
 * `OrganizationAuthorizationContract` to the orgs the actor belongs to, so a
 * user never sees another org's stores.
 */
final class StoreController extends BaseController
{
    private const WITH = ['defaultLanguage', 'defaultCurrency', 'timezone'];

    public function __construct(
        private readonly OrganizationAuthorizationContract $authz,
        private readonly StoreRepositoryContract $stores,
        private readonly UpdateStoreAction $update,
    ) {}

    /**
     * GET /api/v1/stores — every store in an org the actor actively belongs to.
     */
    public function index(): JsonResponse
    {
        $organizationIds = $this->authz->organizationIdsForUser((int) current_actor()?->getKey());

        return $this->ok(StoreResource::collection($this->stores->forOrganizations($organizationIds)));
    }

    /**
     * GET /api/v1/stores/{store}
     */
    public function show(Store $store): JsonResponse
    {
        $this->authorize('view', $store);

        return $this->ok(new StoreResource($store->load(self::WITH)));
    }

    /**
     * PATCH /api/v1/stores/{store}
     */
    public function update(UpdateStoreRequest $request, Store $store): JsonResponse
    {
        $updated = $this->update->run($store, $request->toDto());

        return $this->ok(new StoreResource($updated->load(self::WITH)));
    }
}
