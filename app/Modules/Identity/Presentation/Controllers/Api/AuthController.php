<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Controllers\Api;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Identity\Application\Services\AuthService;
use App\Modules\Identity\Presentation\Requests\LoginRequest;
use App\Modules\Identity\Presentation\Requests\RegisterRequest;
use App\Modules\Identity\Presentation\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authentication endpoints for the Next.js storefront.
 *
 * The controller is thin by contract: resolve a DTO, call the service, return
 * a resource. It contains no credential checking, no session handling and no
 * queries — all of that is in AuthService and its actions, so the Filament
 * panels and the console go through exactly the same code.
 *
 * Every route here is rate limited by `throttle:auth` (5/min per email AND per
 * IP) — @see AppServiceProvider::configureRateLimiting().
 */
final class AuthController extends BaseController
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * POST /api/v1/auth/login
     *
     * Failures throw AuthenticationFailed, which renders itself as the
     * standard error envelope. The controller never distinguishes a missing
     * account from a wrong password — that logic is deliberately not here.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $session = $this->auth->attempt($request->toDto(), $request);

        $user = $session->user->load(['language', 'country', 'currency', 'timezone']);

        return $this->ok(
            data: [
                'user' => new UserResource($user),
                'session_id' => $session->uuid,
            ],
            message: __('auth.signed_in'),
        );
    }

    /**
     * POST /api/v1/auth/register
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->auth->registerUser($request->toDto());

        /*
        | 201 with the user but NO session. Registration does not sign anyone
        | in: a seller is Pending until approved, and a customer must verify
        | their address. Returning a session here would paper over both.
        */
        return $this->created(
            data: [
                'user' => new UserResource($user->load(['language', 'country', 'currency'])),
                'requires_verification' => $user->email_verified_at === null,
            ],
            message: __('auth.registered'),
        );
    }

    /**
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $user = current_actor();

        if ($user !== null) {
            $this->auth->signOut($user, $request);
        }

        return $this->noContent();
    }

    /**
     * GET /api/v1/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = current_actor();

        if ($user === null) {
            return $this->ok(null);
        }

        return $this->ok(
            new UserResource($user->load(['language', 'country', 'currency', 'timezone'])),
        );
    }
}
