<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Controllers\Api\Admin;

use App\Core\Domain\Context\AuditContext;
use App\Core\Presentation\Controllers\BaseController;
use App\Models\User;
use App\Modules\Identity\Application\Actions\AdminUpdateUserAction;
use App\Modules\Identity\Application\Actions\RequestPasswordResetAction;
use App\Modules\Identity\Application\Services\TwoFactorService;
use App\Modules\Identity\Domain\Contracts\LoginAttemptRepositoryContract;
use App\Modules\Identity\Domain\Contracts\UserRepositoryContract;
use App\Modules\Identity\Domain\DTOs\PasswordResetRequestDTO;
use App\Modules\Identity\Presentation\Requests\Admin\AdminDisableTwoFactorRequest;
use App\Modules\Identity\Presentation\Requests\Admin\AdminResetPasswordRequest;
use App\Modules\Identity\Presentation\Requests\Admin\AdminUpdateUserRequest;
use App\Modules\Identity\Presentation\Resources\LoginAttemptResource;
use App\Modules\Identity\Presentation\Resources\UserResource;
use App\Shared\Enums\UserType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The administrator's window onto other people's accounts.
 *
 * EVERY ACTION IS POLICY-GUARDED. The read endpoints authorise here; the write
 * endpoints authorise inside their FormRequest (which is where the privilege-
 * escalation guard against super-admins also lives). No permission string
 * appears in this controller — the policy owns them, and the routes carry no
 * middleware `can:` that could drift from the policy.
 *
 * Account CHANGES are forensically recorded: an update writes an audit entry
 * (with the admin's reason) through User's Auditable trait; disabling 2FA writes
 * one through the security-audit listener, since its columns are excluded from
 * the diff trail. @see docs/modules/Identity.md §Q6, ADR-027.
 */
final class UserController extends BaseController
{
    public function __construct(
        private readonly UserRepositoryContract $users,
        private readonly AdminUpdateUserAction $updateUser,
        private readonly RequestPasswordResetAction $requestReset,
        private readonly TwoFactorService $twoFactor,
        private readonly LoginAttemptRepositoryContract $attempts,
    ) {}

    /**
     * GET /api/v1/admin/users
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $paginator = $this->users->paginate(
            type: UserType::tryFrom((string) $request->query('type', '')),
            search: $request->query('search') !== null ? (string) $request->query('search') : null,
            perPage: $this->perPage(),
        );

        return $this->paginated(
            $paginator,
            UserResource::collection($paginator->getCollection()),
        );
    }

    /**
     * GET /api/v1/admin/users/{user}
     */
    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return $this->ok(new UserResource($user));
    }

    /**
     * PATCH /api/v1/admin/users/{user}
     */
    public function update(AdminUpdateUserRequest $request, User $user): JsonResponse
    {
        // The action resolves the concrete subclass so the audit entry lands on
        // the same morph as a self-service edit — one place, no caller repeats it.
        $updated = $this->updateUser->run($user, $request->toDto());

        return $this->ok(
            data: new UserResource($updated),
            message: __('auth.user_updated'),
        );
    }

    /**
     * POST /api/v1/admin/users/{user}/reset-password
     *
     * Reuses the self-service reset flow: a reset LINK is emailed and nothing is
     * returned (ADR-025). Authorised as `user.reset_password`.
     */
    public function resetPassword(AdminResetPasswordRequest $request, User $user): JsonResponse
    {
        // The reason rides the audit context so the SECURITY_PASSWORD_RESET_ISSUED
        // entry records WHY an operator triggered the reset.
        AuditContext::withReasonFor(
            $request->validated('reason'),
            fn () => $this->requestReset->run(new PasswordResetRequestDTO($user->email, $user->type)),
        );

        return $this->ok(message: __('auth.admin_reset_sent'));
    }

    /**
     * DELETE /api/v1/admin/users/{user}/two-factor
     *
     * The reason rides the audit context so the forensic entry the security-
     * audit listener writes records WHY an operator cleared someone's 2FA.
     */
    public function disableTwoFactor(AdminDisableTwoFactorRequest $request, User $user): JsonResponse
    {
        AuditContext::withReasonFor(
            $request->validated('reason'),
            fn () => $this->twoFactor->disable($user, byAdministrator: true),
        );

        return $this->ok(message: __('auth.admin_two_factor_disabled'));
    }

    /**
     * GET /api/v1/admin/users/{user}/login-history
     */
    public function loginHistory(User $user): JsonResponse
    {
        $this->authorize('viewLoginHistory', $user);

        return $this->ok(
            LoginAttemptResource::collection($this->attempts->forUser($user)),
        );
    }
}
