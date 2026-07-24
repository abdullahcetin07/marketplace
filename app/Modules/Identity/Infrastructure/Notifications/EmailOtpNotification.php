<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Notifications;

use App\Core\Application\Notifications\BaseNotification;
use App\Shared\Enums\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Delivers a one-time login code by email (Q5 fallback).
 *
 * MAIL ONLY. The code is a login factor; a database notification carrying it
 * would be readable by an existing session, defeating the point.
 *
 * A security alert, so it ignores opt-out — a user relying on this to get in
 * must always receive it.
 *
 * The code is the whole payload and is short-lived; there is no link and no
 * other credential.
 */
final class EmailOtpNotification extends BaseNotification
{
    public function __construct(private readonly string $code) {}

    /**
     * @return array<int, NotificationType>
     */
    public function channels(): array
    {
        return [NotificationType::Mail];
    }

    public function isSecurityAlert(): bool
    {
        return true;
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $minutes = (int) ceil(
            ((int) config('marketplace.security.two_factor.email_otp_ttl_seconds', 300)) / 60,
        );

        return (new MailMessage)
            ->subject(__('auth.mail.otp_subject'))
            ->greeting(__('auth.mail.greeting', ['name' => $notifiable->first_name]))
            ->line(__('auth.mail.otp_intro'))
            ->line('**'.$this->code.'**')
            ->line(__('auth.mail.otp_expiry', ['minutes' => $minutes]))
            ->line(__('auth.mail.otp_ignore'));
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        // The code is never a tag — Horizon shows tags widely.
        return ['two-factor-otp'];
    }
}
