<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Controllers\Api;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Identity\Application\Actions\ResendVerificationAction;
use App\Modules\Identity\Application\Actions\VerifyEmailAction;
use App\Modules\Identity\Presentation\Requests\ResendVerificationRequest;
use App\Modules\Identity\Presentation\Requests\VerifyEmailRequest;
use Illuminate\Http\JsonResponse;

/**
 * Email verification endpoints.
 *
 * Both public, both `throttle:auth` — the caller is unauthenticated (a link
 * from an inbox, or a just-registered account with no session).
 */
final class EmailVerificationController extends BaseController
{
    public function __construct(
        private readonly VerifyEmailAction $verify,
        private readonly ResendVerificationAction $resend,
    ) {}

    /**
     * POST /api/v1/auth/email/verify/{uuid}/{hash}
     *
     * The request's signature check is the authorisation — an invalid or
     * expired link never reaches here (403 first). The action then matches the
     * hash to the account and is idempotent on a repeat click.
     */
    public function verify(VerifyEmailRequest $request): JsonResponse
    {
        $this->verify->run($request->uuid(), $request->hash());

        return $this->ok(
            data: null,
            message: __('auth.email_verified'),
        );
    }

    /**
     * POST /api/v1/auth/email/resend
     *
     * ALWAYS the same envelope (ADR-025). The action returns void whether the
     * address is unknown, already verified, or freshly re-sent — none of which
     * this method can tell apart, which is the point.
     */
    public function resend(ResendVerificationRequest $request): JsonResponse
    {
        $this->resend->run($request->toDto());

        return $this->ok(
            data: null,
            message: __('auth.verification_sent'),
        );
    }
}
