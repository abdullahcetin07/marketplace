<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Contracts\InvitationTokenizerContract;
use App\Modules\Organization\Domain\Contracts\OrganizationInvitationRepositoryContract;
use App\Modules\Organization\Domain\DTOs\InviteMemberDTO;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Events\OrganizationMemberInvited;
use App\Modules\Organization\Domain\Exceptions\InvitationException;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationInvitation;
use App\Modules\Organization\Infrastructure\Notifications\OrganizationInvitationNotification;
use App\Shared\Enums\InvitationStatus;
use Illuminate\Support\Facades\Notification;

/**
 * Invite an email address to join an organization (ADR-031).
 *
 * The raw token is generated, its HASH stored, and the raw handed only to the
 * emailed notification — never persisted or returned. Issuing a new invitation
 * cancels any prior pending one for the same address, so a mailbox holds at most
 * one live invitation per organization. The Owner role can never be invited
 * (ADR-029).
 *
 * The email and the event fire AFTER commit, so a rolled-back invite neither
 * mails a dead link nor announces itself.
 */
final class InviteMemberAction extends BaseAction
{
    private string $rawToken;

    private string $organizationName;

    private OrganizationInvitation $invitation;

    public function __construct(
        private readonly InvitationTokenizerContract $tokenizer,
        private readonly OrganizationInvitationRepositoryContract $invitations,
    ) {}

    public function handle(mixed ...$arguments): OrganizationInvitation
    {
        /** @var Organization $organization */
        $organization = $arguments[0];
        /** @var InviteMemberDTO $data */
        $data = $arguments[1];

        if ($data->role === OrganizationRole::Owner) {
            throw InvitationException::cannotInviteOwner();
        }

        // Invalidate any prior pending invitation for this address.
        $this->invitations->pendingFor($data->organizationId, $data->normalisedEmail())?->markCancelled();

        $this->rawToken = $this->tokenizer->generate();
        $this->organizationName = $organization->display_name ?? $organization->legal_name;

        $days = (int) config('marketplace.organization.invitation_expiry_days', 7);

        $this->invitation = OrganizationInvitation::query()->create([
            'organization_id' => $data->organizationId,
            'email' => $data->normalisedEmail(),
            'role' => $data->role,
            'token_hash' => $this->tokenizer->hash($this->rawToken),
            'status' => InvitationStatus::Pending,
            'invited_by' => $data->invitedBy,
            'expires_at' => now()->addDays(max(1, $days)),
        ]);

        return $this->invitation;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Organization $organization */
        $organization = $arguments[0];
        /** @var InviteMemberDTO $data */
        $data = $arguments[1];

        Notification::route('mail', $data->normalisedEmail())->notify(
            new OrganizationInvitationNotification(
                $this->rawToken,
                $this->organizationName,
                $data->role->label(),
            ),
        );

        OrganizationMemberInvited::dispatch(
            $data->organizationId,
            $organization->uuid,
            $data->normalisedEmail(),
            $data->role->value,
            $data->invitedBy,
        );
    }
}
