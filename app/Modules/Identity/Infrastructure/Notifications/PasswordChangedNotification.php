<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Notifications;

use App\Core\Application\Notifications\BaseNotification;
use App\Shared\Enums\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells the account owner their password changed.
 *
 * THE POINT IS THE UNEXPECTED CASE. A user who changed their own password
 * learns nothing from this. A user who did not has just been told their
 * account is compromised, while the attacker still has to get past a mailbox
 * they do not control.
 *
 * Security alert — ignores opt-out preferences. Mail **and** database, so it
 * survives a mailbox the user no longer reads.
 */
final class PasswordChangedNotification extends BaseNotification
{
    public function __construct(private readonly bool $viaReset = false) {}

    /**
     * @return array<int, NotificationType>
     */
    public function channels(): array
    {
        return [NotificationType::Mail, NotificationType::Database];
    }

    public function isSecurityAlert(): bool
    {
        return true;
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('auth.mail.changed_subject'))
            ->greeting(__('auth.mail.greeting', ['name' => $notifiable->first_name]))
            ->line($this->viaReset
                ? __('auth.mail.changed_via_reset')
                : __('auth.mail.changed_intro'))
            ->line(__('auth.mail.changed_sessions_revoked'))
            ->line(__('auth.mail.changed_warning'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'type' => 'password_changed',
            'via_reset' => $this->viaReset,
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
