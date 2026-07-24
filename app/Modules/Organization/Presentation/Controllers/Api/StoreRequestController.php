<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Controllers\Api;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Organization\Application\Actions\CancelStoreOpeningRequestAction;
use App\Modules\Organization\Application\Actions\CreateStoreOpeningRequestAction;
use App\Modules\Organization\Application\Actions\SubmitStoreOpeningRequestAction;
use App\Modules\Organization\Domain\Contracts\StoreOpeningRequestRepositoryContract;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\StoreOpeningRequest;
use App\Modules\Organization\Presentation\Requests\CreateStoreOpeningRequestRequest;
use App\Modules\Organization\Presentation\Resources\StoreOpeningRequestResource;
use Illuminate\Http\JsonResponse;

/**
 * A seller's Store Opening Requests (ADR-028). Creating drafts and submitting
 * them into the admin queue — no store is ever created here.
 */
final class StoreRequestController extends BaseController
{
    public function __construct(
        private readonly StoreOpeningRequestRepositoryContract $requests,
        private readonly CreateStoreOpeningRequestAction $create,
        private readonly SubmitStoreOpeningRequestAction $submit,
        private readonly CancelStoreOpeningRequestAction $cancel,
    ) {}

    /**
     * GET /organizations/{organization}/store-requests
     */
    public function index(Organization $organization): JsonResponse
    {
        $this->authorize('view', $organization);

        return $this->ok(
            StoreOpeningRequestResource::collection($this->requests->forOrganization($organization->getKey())),
        );
    }

    /**
     * POST /organizations/{organization}/store-requests
     */
    public function store(CreateStoreOpeningRequestRequest $request, Organization $organization): JsonResponse
    {
        $storeRequest = $this->create->run($request->toDto($organization->getKey()));

        return $this->created(new StoreOpeningRequestResource($storeRequest));
    }

    /**
     * POST /organizations/{organization}/store-requests/{storeRequest}/submit
     */
    public function submit(Organization $organization, StoreOpeningRequest $storeRequest): JsonResponse
    {
        $this->authorize('submit', $storeRequest);

        return $this->ok(new StoreOpeningRequestResource($this->submit->run($storeRequest)));
    }

    /**
     * POST /organizations/{organization}/store-requests/{storeRequest}/cancel
     */
    public function cancel(Organization $organization, StoreOpeningRequest $storeRequest): JsonResponse
    {
        $this->authorize('cancel', $storeRequest);

        return $this->ok(new StoreOpeningRequestResource($this->cancel->run($storeRequest)));
    }
}
