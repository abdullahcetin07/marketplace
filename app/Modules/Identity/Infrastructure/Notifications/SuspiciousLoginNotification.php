<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Notifications;

use App\Core\Application\Notifications\BaseNotification;
use App\Shared\Enums\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Warns the account owner that their address is under a sign-in attack (Q6).
 *
 * THE POINT IS THE OWNER WHO IS NOT DOING THIS. Someone grinding their password
 * is the earliest warning they will get, while the attacker still has to get
 * past a mailbox they do not control. It deliberately reassures that the
 * attempts FAILED — the alarming part is the attempt, not a breach.
 *
 * Security alert — ignores opt-out preferences, and goes to the database inbox
 * as well as mail so it survives a mailbox the user no longer reads.
 */
final class SuspiciousLoginNotification extends BaseNotification
{
    public function __construct(
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
            ->subject(__('auth.mail.suspicious_subject'))
            ->greeting(__('auth.mail.greeting', ['name' => $notifiable->first_name]))
            ->line(__('auth.mail.suspicious_intro'))
            ->line(__('auth.mail.suspicious_reassure'))
            ->line(__('auth.mail.suspicious_action'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'type' => 'suspicious_login',
            // Counts only — never the attacker's IPs or the internal signal.
            'failure_count' => $this->failureCount,
            'distinct_ips' => $this->distinctIps,
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
