<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure\Notifications;

use App\Core\Application\Notifications\BaseNotification;
use App\Core\Infrastructure\Frontend\FrontendUrl;
use App\Shared\Enums\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Invites someone to join an organization (ADR-031).
 *
 * MAIL ONLY. The recipient may not have an account yet, so there is no database
 * inbox to write to — the whole point of an invitation is that it precedes
 * membership. The raw token travels ONLY in the accept link built from
 * configuration (ADR-025); it is never stored or returned by an API.
 *
 * If the recipient has no account, the accept link routes them through
 * registration first and back (ADR-031) — the backend does not create the
 * account.
 */
final class OrganizationInvitationNotification extends BaseNotification
{
    public function __construct(
        private readonly string $rawToken,
        private readonly string $organizationName,
        private readonly string $roleLabel,
    ) {}

    /**
     * @return array<int, NotificationType>
     */
    public function channels(): array
    {
        return [NotificationType::Mail];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $url = FrontendUrl::compose(
            (string) config('marketplace.frontend.organization_invitation_path', '/organizations/invitations/{token}'),
            ['token' => $this->rawToken],
        );

        return (new MailMessage)
            ->subject(__('organization.invitation.subject', ['organization' => $this->organizationName]))
            ->line(__('organization.invitation.intro', [
                'organization' => $this->organizationName,
                'role' => $this->roleLabel,
            ]))
            ->action(__('organization.invitation.action'), $url)
            ->line(__('organization.invitation.expiry'))
            ->line(__('organization.invitation.no_account'));
    }
}
