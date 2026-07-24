<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation\Controllers\Api\Admin;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Store\Application\Actions\ArchiveStoreAction;
use App\Modules\Store\Application\Actions\ReinstateStoreAction;
use App\Modules\Store\Application\Actions\SuspendStoreAction;
use App\Modules\Store\Domain\Contracts\StoreRepositoryContract;
use App\Modules\Store\Domain\Models\Store;
use App\Modules\Store\Presentation\Requests\SuspendStoreRequest;
use App\Modules\Store\Presentation\Resources\StoreResource;
use Illuminate\Http\JsonResponse;

/**
 * The admin store surface — PLATFORM-LEVEL ONLY (view, suspend, reinstate,
 * archive). Admins do not manage a store's content; that is the seller's job
 * through membership. Every action is gated by a `store.*` Spatie permission via
 * StorePolicy, never by a `can:` middleware that could drift from it.
 */
final class StoreController extends BaseController
{
    private const WITH = ['defaultLanguage', 'defaultCurrency', 'timezone'];

    public function __construct(
        private readonly StoreRepositoryContract $stores,
        private readonly SuspendStoreAction $suspend,
        private readonly ReinstateStoreAction $reinstate,
        private readonly ArchiveStoreAction $archive,
    ) {}

    /**
     * GET /api/v1/admin/stores
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Store::class);

        $stores = $this->stores->paginate($this->perPage());

        return $this->paginated($stores, StoreResource::collection($stores->items()));
    }

    /**
     * GET /api/v1/admin/stores/{store}
     */
    public function show(Store $store): JsonResponse
    {
        $this->authorize('view', $store);

        return $this->ok(new StoreResource($store->load(self::WITH)));
    }

    /**
     * POST /api/v1/admin/stores/{store}/suspend
     */
    public function suspend(SuspendStoreRequest $request, Store $store): JsonResponse
    {
        $suspended = $this->suspend->run($store, current_actor(), $request->input('reason'));

        return $this->ok(new StoreResource($suspended->load(self::WITH)));
    }

    /**
     * POST /api/v1/admin/stores/{store}/reinstate
     */
    public function reinstate(Store $store): JsonResponse
    {
        $this->authorize('reinstate', $store);

        $reinstated = $this->reinstate->run($store, current_actor());

        return $this->ok(new StoreResource($reinstated->load(self::WITH)));
    }

    /**
     * POST /api/v1/admin/stores/{store}/archive
     */
    public function archive(Store $store): JsonResponse
    {
        $this->authorize('archive', $store);

        return $this->ok(new StoreResource($this->archive->run($store)->load(self::WITH)));
    }
}
