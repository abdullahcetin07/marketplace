<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Controllers\Api;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Identity\Application\Actions\RequestPasswordResetAction;
use App\Modules\Identity\Application\Actions\ResetPasswordAction;
use App\Modules\Identity\Presentation\Requests\ForgotPasswordRequest;
use App\Modules\Identity\Presentation\Requests\ResetPasswordRequest;
use Illuminate\Http\JsonResponse;

/**
 * Password reset endpoints.
 *
 * Thin by contract: resolve a DTO, call an action, return an envelope. No
 * credential handling happens here — the token never reaches this layer.
 *
 * Both routes carry `throttle:auth` (5/min per email AND per IP).
 */
final class PasswordController extends BaseController
{
    public function __construct(
        private readonly RequestPasswordResetAction $requestReset,
        private readonly ResetPasswordAction $reset,
    ) {}

    /**
     * POST /api/v1/auth/password/forgot
     *
     * ALWAYS the same envelope (ADR-025). The action returns void and never
     * throws for an unknown address, so this method has nothing to branch on —
     * which is precisely the property being protected. A `data` key is not
     * emitted at all: there is nothing to say without leaking.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->requestReset->run($request->toDto());

        return $this->ok(
            data: null,
            message: __('auth.password_reset_requested'),
        );
    }

    /**
     * POST /api/v1/auth/password/reset
     *
     * Throws `PasswordResetFailed` (`RESET_TOKEN_INVALID`) on any failure —
     * expired, already used, wrong address. One reason, so a guessed token
     * cannot confirm an address.
     *
     * Returns no session. The user signs in with the new password, which
     * proves possession rather than assuming it.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $this->reset->run($request->toDto());

        return $this->ok(
            data: null,
            message: __('auth.password_reset'),
        );
    }
}
