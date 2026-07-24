<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Controllers\Api;

use App\Core\Domain\Contracts\InvitationTokenizerContract;
use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Organization\Application\Actions\AcceptInvitationAction;
use App\Modules\Organization\Application\Actions\RejectInvitationAction;
use App\Modules\Organization\Domain\Contracts\OrganizationInvitationRepositoryContract;
use App\Modules\Organization\Domain\Exceptions\InvitationException;
use Illuminate\Http\JsonResponse;

/**
 * The invitee surface (ADR-031). Behind auth:sanctum — an invitation is only
 * ever accepted by an authenticated account, and the account must be the invited
 * recipient (enforced by the action's email match). No account is created here.
 */
final class InvitationController extends BaseController
{
    public function __construct(
        private readonly InvitationTokenizerContract $tokenizer,
        private readonly OrganizationInvitationRepositoryContract $invitations,
        private readonly AcceptInvitationAction $accept,
        private readonly RejectInvitationAction $reject,
    ) {}

    /**
     * POST /api/v1/organization-invitations/{token}/accept
     */
    public function acceptInvitation(string $token): JsonResponse
    {
        $this->accept->run($token, current_actor());

        return $this->ok(message: __('organization.invitation_accepted'));
    }

    /**
     * POST /api/v1/organization-invitations/{token}/reject
     */
    public function rejectInvitation(string $token): JsonResponse
    {
        $invitation = $this->invitations->findByTokenHash($this->tokenizer->hash($token));

        if ($invitation === null) {
            throw InvitationException::notAcceptable();
        }

        // Only the invited recipient may reject it.
        $this->authorize('reject', $invitation);
        $this->reject->run($invitation);

        return $this->ok(message: __('organization.invitation_rejected'));
    }
}
