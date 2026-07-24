<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Notifications;

use App\Core\Application\Notifications\BaseNotification;
use App\Shared\Enums\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells administrators an account is under a sustained sign-in attack (Q6).
 *
 * Distinct from the owner's alert: this one names the targeted address and the
 * attempt volume, because an admin is triaging an incident, not being warned
 * about their own account. It points them at the forensic trail rather than
 * carrying the whole picture.
 *
 * Security alert — ignores opt-out preferences. Per-admin subscription routing
 * is deferred to the Notification module; until then every active admin is a
 * recipient (@see UserRepository::securityAlertRecipients).
 */
final class SecurityAlertNotification extends BaseNotification
{
    public function __construct(
        private readonly string $email,
        private readonly int $failureCount,
        private readonly int $distinctIps,
    ) {}

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
            ->subject(__('auth.mail.admin_alert_subject'))
            ->greeting(__('auth.mail.greeting', ['name' => $notifiable->first_name]))
            ->line(__('auth.mail.admin_alert_intro'))
            ->line(__('auth.mail.admin_alert_detail', [
                'email' => $this->email,
                'count' => $this->failureCount,
                'ips' => $this->distinctIps,
            ]))
            ->line(__('auth.mail.admin_alert_action'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'type' => 'security_alert',
            'target_email' => $this->email,
            'failure_count' => $this->failureCount,
            'distinct_ips' => $this->distinctIps,
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
