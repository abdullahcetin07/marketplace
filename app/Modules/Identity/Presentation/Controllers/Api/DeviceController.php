<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Controllers\Api;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Identity\Application\Actions\ForgetDeviceAction;
use App\Modules\Identity\Application\Actions\TrustDeviceAction;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryContract;
use App\Modules\Identity\Domain\Models\UserDevice;
use App\Modules\Identity\Presentation\Resources\DeviceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The user's recognised devices.
 *
 * Authorisation is ALWAYS through `DevicePolicy`, never an inline ownership
 * check — a device list is fingerprints and IPs, and reading someone else's is
 * a privacy breach. The policy scopes reads as well as writes.
 *
 * Route model binding is by UUID (`HasUuid::getRouteKeyName()`), so no internal
 * id is ever in a URL.
 */
final class DeviceController extends BaseController
{
    public function __construct(
        private readonly DeviceRepositoryContract $devices,
        private readonly TrustDeviceAction $trust,
        private readonly ForgetDeviceAction $forget,
    ) {}

    /**
     * GET /api/v1/devices
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', UserDevice::class);

        return $this->ok(
            DeviceResource::collection($this->devices->forUser(current_actor())),
        );
    }

    /**
     * POST /api/v1/devices/{device}/trust
     */
    public function trust(Request $request, UserDevice $device): JsonResponse
    {
        $this->authorize('update', $device);

        $this->trust->run($device, current_actor());

        return $this->ok(
            data: new DeviceResource($device->refresh()),
            message: __('auth.device_trusted'),
        );
    }

    /**
     * DELETE /api/v1/devices/{device}
     *
     * "Forget" ends access, not just the row — every session on the device is
     * revoked. @see ForgetDeviceAction.
     */
    public function destroy(Request $request, UserDevice $device): JsonResponse
    {
        $this->authorize('delete', $device);

        $this->forget->run($device, current_actor());

        return $this->noContent();
    }
}
