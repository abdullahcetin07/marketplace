<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Notifications;

use App\Core\Application\Notifications\BaseNotification;
use App\Core\Infrastructure\Frontend\FrontendUrl;
use App\Shared\Enums\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The out-of-band delivery channel for a reset token (ADR-025).
 *
 * **This is the only place the token is exposed**, and it goes to the mailbox
 * its owner controls. Not the API response, not an event, not a log line.
 *
 * MAIL ONLY — no database channel. An in-app notification containing a reset
 * link would be readable by anyone already holding a session, which is exactly
 * who a reset is meant to lock out.
 *
 * A security alert, so it ignores opt-out preferences: a user must not be able
 * to mute the message that lets them recover their account.
 *
 * @see App\Core\Infrastructure\Frontend\FrontendUrl
 */
final class ResetPasswordNotification extends BaseNotification
{
    public function __construct(
        private readonly string $token,
        private readonly string $email,
    ) {}

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
        // Composed from configuration — the backend never hardcodes a frontend
        // URL, and never hands the token to the frontend through an API.
        $url = FrontendUrl::passwordReset($this->token, $this->email);

        $minutes = (int) config('auth.passwords.customers.expire', 60);

        return (new MailMessage)
            ->subject(__('auth.mail.reset_subject'))
            ->greeting(__('auth.mail.greeting', ['name' => $notifiable->first_name]))
            ->line(__('auth.mail.reset_intro'))
            ->action(__('auth.mail.reset_button'), $url)
            ->line(__('auth.mail.reset_expiry', ['minutes' => $minutes]))
            ->line(__('auth.mail.reset_ignore'));
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        // The token is never a tag — Horizon shows tags to anyone with
        // dashboard access.
        return ['password-reset'];
    }
}
