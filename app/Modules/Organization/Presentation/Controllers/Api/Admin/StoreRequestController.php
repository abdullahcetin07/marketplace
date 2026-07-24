<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Controllers\Api\Admin;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Organization\Application\Actions\ApproveStoreOpeningRequestAction;
use App\Modules\Organization\Application\Actions\RejectStoreOpeningRequestAction;
use App\Modules\Organization\Domain\Contracts\StoreOpeningRequestRepositoryContract;
use App\Modules\Organization\Domain\Models\StoreOpeningRequest;
use App\Modules\Organization\Presentation\Resources\StoreOpeningRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The admin Store Opening Request queue (ADR-028). Approving fires
 * `StoreOpeningApproved`; the Store module creates the store — never here.
 */
final class StoreRequestController extends BaseController
{
    public function __construct(
        private readonly StoreOpeningRequestRepositoryContract $requests,
        private readonly ApproveStoreOpeningRequestAction $approve,
        private readonly RejectStoreOpeningRequestAction $reject,
    ) {}

    /**
     * GET /api/v1/admin/store-requests
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewQueue', StoreOpeningRequest::class);

        $paginator = $this->requests->pendingQueue($this->perPage());

        return $this->paginated($paginator, StoreOpeningRequestResource::collection($paginator->getCollection()));
    }

    /**
     * POST /api/v1/admin/store-requests/{storeRequest}/approve
     */
    public function approve(Request $request, StoreOpeningRequest $storeRequest): JsonResponse
    {
        $this->authorize('approve', $storeRequest);

        $notes = $request->validate(['notes' => ['sometimes', 'nullable', 'string', 'max:1000']])['notes'] ?? null;
        $this->approve->run($storeRequest, $notes, current_actor());

        return $this->ok(message: __('organization.store_request_approved'));
    }

    /**
     * POST /api/v1/admin/store-requests/{storeRequest}/reject
     */
    public function reject(Request $request, StoreOpeningRequest $storeRequest): JsonResponse
    {
        $this->authorize('reject', $storeRequest);

        $notes = $request->validate(['notes' => ['required', 'string', 'max:1000']])['notes'];
        $this->reject->run($storeRequest, $notes, current_actor());

        return $this->ok(message: __('organization.store_request_rejected'));
    }
}
