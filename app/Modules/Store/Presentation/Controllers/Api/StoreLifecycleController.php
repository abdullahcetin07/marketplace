<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation\Controllers\Api;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Store\Application\Actions\ActivateStoreAction;
use App\Modules\Store\Application\Actions\CloseStoreAction;
use App\Modules\Store\Application\Actions\PauseStoreAction;
use App\Modules\Store\Application\Actions\ResumeStoreAction;
use App\Modules\Store\Domain\Models\Store;
use App\Modules\Store\Presentation\Resources\StoreResource;
use Illuminate\Http\JsonResponse;

/**
 * The seller-facing operational-state surface (§7).
 *
 * Each method authorises the matching ability through StorePolicy (`store.manage`
 * on the store's org) and delegates the transition — with its guard and event —
 * to the action. The guard/event logic lives in the action, never here.
 */
final class StoreLifecycleController extends BaseController
{
    private const WITH = ['defaultLanguage', 'defaultCurrency', 'timezone'];

    public function __construct(
        private readonly ActivateStoreAction $activate,
        private readonly PauseStoreAction $pause,
        private readonly ResumeStoreAction $resume,
        private readonly CloseStoreAction $close,
    ) {}

    public function activate(Store $store): JsonResponse
    {
        $this->authorize('activate', $store);

        return $this->respondWith($this->activate->run($store));
    }

    public function pause(Store $store): JsonResponse
    {
        $this->authorize('pause', $store);

        return $this->respondWith($this->pause->run($store));
    }

    public function resume(Store $store): JsonResponse
    {
        $this->authorize('resume', $store);

        return $this->respondWith($this->resume->run($store));
    }

    public function close(Store $store): JsonResponse
    {
        $this->authorize('close', $store);

        return $this->respondWith($this->close->run($store));
    }

    private function respondWith(Store $store): JsonResponse
    {
        return $this->ok(new StoreResource($store->load(self::WITH)));
    }
}
