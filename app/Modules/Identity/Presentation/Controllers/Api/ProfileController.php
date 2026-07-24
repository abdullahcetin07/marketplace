<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Controllers\Api;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Identity\Application\Actions\UpdateProfileAction;
use App\Modules\Identity\Application\Services\AuthService;
use App\Modules\Identity\Presentation\Requests\ChangePasswordRequest;
use App\Modules\Identity\Presentation\Requests\UpdateProfileRequest;
use App\Modules\Identity\Presentation\Resources\UserResource;
use Illuminate\Http\JsonResponse;

/**
 * The authenticated user's own account.
 *
 * Everything here operates on `current_actor()` — a user acting on themselves.
 * There is no `{uuid}` in these routes: you cannot edit someone else's profile
 * through this controller, which removes an entire class of IDOR by
 * construction. Administering other accounts is a separate admin surface
 * (Phase 8).
 */
final class ProfileController extends BaseController
{
    public function __construct(
        private readonly UpdateProfileAction $updateProfile,
        private readonly AuthService $auth,
    ) {}

    /**
     * PATCH /api/v1/profile
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->updateProfile->run(current_actor(), $request->toDto());

        return $this->ok(
            data: new UserResource($user->load(['language', 'country', 'currency', 'timezone'])),
            message: __('auth.profile_updated'),
        );
    }

    /**
     * POST /api/v1/profile/password
     *
     * The request verifies the CURRENT password before this runs. On success
     * every OTHER session is revoked and the acting one is kept alive — a
     * voluntary change should not sign the user out of the tab they used.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->auth->updatePassword(
            user: current_actor(),
            newPassword: (string) $request->validated('password'),
            request: $request,
            viaReset: false,
            keepCurrentSession: true,
        );

        return $this->ok(
            data: null,
            message: __('auth.password_changed'),
        );
    }
}
