<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Controllers\Api;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Identity\Application\Actions\RequestEmailOtpAction;
use App\Modules\Identity\Application\Services\TwoFactorService;
use App\Modules\Identity\Domain\Exceptions\AuthenticationFailed;
use App\Modules\Identity\Presentation\Requests\ConfirmTwoFactorRequest;
use App\Modules\Identity\Presentation\Requests\DisableTwoFactorRequest;
use App\Modules\Identity\Presentation\Requests\RegenerateRecoveryCodesRequest;
use App\Modules\Identity\Presentation\Requests\RequestEmailOtpRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Two-factor enrolment, challenge fallback, and lifecycle.
 *
 * ON ADR-025 AND SHOWING SECRETS: the reset/verification rule (credentials
 * never in a response) does NOT apply to 2FA enrolment. A TOTP secret and
 * recovery codes MUST be shown to the user setting them up — there is no
 * out-of-band channel for "here is your authenticator secret", and the user is
 * already authenticated as themselves. What ADR-025 forbids is returning a
 * bearer credential that grants a *future* request to *anyone* holding it; an
 * enrolment secret shown once to its own owner is a different thing.
 *
 * The email-OTP endpoint is the one exception to that — it IS a login
 * credential, so it goes out by email only.
 */
final class TwoFactorController extends BaseController
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly RequestEmailOtpAction $requestOtp,
    ) {}

    /**
     * GET /api/v1/two-factor
     */
    public function status(Request $request): JsonResponse
    {
        $user = current_actor();

        return $this->ok([
            'enabled' => $user->hasTwoFactorEnabled(),
            'confirmed' => $user->two_factor_confirmed_at !== null,
            'required' => $user->requiresTwoFactor(),
            'recovery_codes_remaining' => $user->hasTwoFactorEnabled()
                ? $this->twoFactor->remainingRecoveryCodes($user)
                : 0,
        ]);
    }

    /**
     * POST /api/v1/two-factor/enable
     *
     * Starts enrolment: a secret and its provisioning URI, for the frontend to
     * render as a QR code. Not protected until /confirm succeeds.
     */
    public function enable(Request $request): JsonResponse
    {
        $user = current_actor();

        // Re-enabling would silently unconfirm a live 2FA setup. Refuse — the
        // user must disable first, deliberately.
        if ($user->hasTwoFactorEnabled()) {
            return $this->error('TWO_FACTOR_ALREADY_ENABLED', __('errors.two_factor_already_enabled'), 409);
        }

        $this->twoFactor->generateSecret($user);

        return $this->ok([
            'secret' => $user->two_factor_secret,
            'provisioning_uri' => $this->twoFactor->provisioningUri($user),
        ], __('auth.two_factor_enrolment_started'));
    }

    /**
     * POST /api/v1/two-factor/confirm
     *
     * Confirms with a live code and returns the recovery codes — shown ONCE.
     */
    public function confirm(ConfirmTwoFactorRequest $request): JsonResponse
    {
        $codes = $this->twoFactor->confirm(current_actor(), $request->code());

        if ($codes === null) {
            throw AuthenticationFailed::twoFactorInvalid();
        }

        return $this->ok(
            data: ['recovery_codes' => $codes],
            message: __('auth.two_factor_enabled'),
        );
    }

    /**
     * DELETE /api/v1/two-factor
     */
    public function disable(DisableTwoFactorRequest $request): JsonResponse
    {
        $this->twoFactor->disable(current_actor());

        return $this->ok(data: null, message: __('auth.two_factor_disabled'));
    }

    /**
     * POST /api/v1/two-factor/recovery-codes
     */
    public function regenerateRecoveryCodes(RegenerateRecoveryCodesRequest $request): JsonResponse
    {
        $codes = $this->twoFactor->regenerateRecoveryCodes(current_actor());

        return $this->ok(
            data: ['recovery_codes' => $codes],
            message: __('auth.recovery_codes_generated'),
        );
    }

    /**
     * POST /api/v1/two-factor/email-otp
     *
     * PUBLIC — requested mid-login. Same envelope whether or not a code was
     * sent; the action re-proves the password and only emails a code to an
     * account that exists and has 2FA enabled.
     */
    public function requestEmailOtp(RequestEmailOtpRequest $request): JsonResponse
    {
        $this->requestOtp->run($request->toDto());

        return $this->ok(data: null, message: __('auth.email_otp_sent'));
    }

    /**
     * The ADR-009 error envelope for a non-exception failure.
     */
    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => $code,
            'message' => $message,
        ], $status);
    }
}
