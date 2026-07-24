<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Contracts\InvitationTokenizerContract;
use App\Modules\Organization\Domain\Exceptions\InvitationException;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationInvitation;
use App\Modules\Organization\Infrastructure\Notifications\OrganizationInvitationNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Re-send a pending invitation with a fresh token and expiry.
 *
 * Issuing a NEW token invalidates the old one (the stored hash is replaced), so
 * an intercepted-but-unused earlier link stops working — the same
 * one-live-token guarantee as a re-issued password reset. Only a pending
 * invitation can be resent.
 */
final class ResendInvitationAction extends BaseAction
{
    private string $rawToken;

    private string $email;

    private string $organizationName;

    private string $roleLabel;

    public function __construct(
        private readonly InvitationTokenizerContract $tokenizer,
    ) {}

    public function handle(mixed ...$arguments): OrganizationInvitation
    {
        /** @var OrganizationInvitation $invitation */
        $invitation = $arguments[0];

        if (! $invitation->isPending()) {
            throw InvitationException::notAcceptable();
        }

        $this->rawToken = $this->tokenizer->generate();
        $days = (int) config('marketplace.organization.invitation_expiry_days', 7);

        $invitation->forceFill([
            'token_hash' => $this->tokenizer->hash($this->rawToken),
            'expires_at' => now()->addDays(max(1, $days)),
        ])->save();

        // Resolve the org name by query rather than lazy-loading the relation.
        $organization = Organization::query()->whereKey($invitation->organization_id)->first();

        $this->email = $invitation->email;
        $this->organizationName = $organization?->display_name ?? $organization?->legal_name ?? '';
        $this->roleLabel = $invitation->role->label();

        return $invitation;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        Notification::route('mail', $this->email)->notify(
            new OrganizationInvitationNotification($this->rawToken, $this->organizationName, $this->roleLabel),
        );
    }
}
