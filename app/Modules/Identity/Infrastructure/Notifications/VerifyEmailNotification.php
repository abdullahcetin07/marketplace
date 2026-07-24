<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Notifications;

use App\Core\Infrastructure\Frontend\FrontendUrl;
use App\Core\Application\Notifications\BaseNotification;
use App\Shared\Enums\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

/**
 * The out-of-band delivery of an email verification link (ADR-025).
 *
 * HOW THE LINK STAYS FRONTEND-AGNOSTIC AND STILL VERIFIABLE:
 *
 *  1. Sign the API CALLBACK route — `api.v1.auth.email.verify` — with the
 *     user's UUID and `sha1(email)`, expiring after the configured lifetime.
 *     The signature is the real credential; `sha1(email)` alone is guessable.
 *  2. Lift the `expires` + `signature` query off that signed URL.
 *  3. Point the emailed link at the FRONTEND path, carrying those params.
 *
 * When the user clicks, the frontend calls the API callback and appends the
 * same params verbatim — so `hasValidSignature()` on the backend still holds,
 * because it was computed over exactly that API URL.
 *
 * The signature travels by email only. It is never in an API response.
 *
 * MAIL ONLY: a database notification carrying a verification link would be
 * readable by any existing session, and verification is meant to prove control
 * of the mailbox specifically.
 *
 * @see App\Core\Infrastructure\Frontend\FrontendUrl
 */
final class VerifyEmailNotification extends BaseNotification
{
    /**
     * @return array<int, NotificationType>
     */
    public function channels(): array
    {
        return [NotificationType::Mail];
    }

    public function isSecurityAlert(): bool
    {
        // Not a warning, but it must bypass opt-out: an account cannot be
        // used until it is verified, so the user must always receive this.
        return true;
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $url = $this->verificationUrl($notifiable);

        $minutes = (int) config('marketplace.security.email_verification.expire_minutes', 60);

        return (new MailMessage)
            ->subject(__('auth.mail.verify_subject'))
            ->greeting(__('auth.mail.greeting', ['name' => $notifiable->first_name]))
            ->line(__('auth.mail.verify_intro'))
            ->action(__('auth.mail.verify_button'), $url)
            ->line(__('auth.mail.verify_expiry', ['minutes' => $minutes]))
            ->line(__('auth.mail.verify_ignore'));
    }

    /**
     * Build the frontend link with the signed callback params attached.
     */
    private function verificationUrl(mixed $notifiable): string
    {
        $signed = URL::temporarySignedRoute(
            'api.v1.auth.email.verify',
            now()->addMinutes((int) config('marketplace.security.email_verification.expire_minutes', 60)),
            [
                // UUID, never the internal id (§8).
                'uuid' => $notifiable->uuid,
                'hash' => sha1((string) $notifiable->getEmailForVerification()),
            ],
        );

        // Lift just the expires + signature query off the signed API URL.
        parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);

        return FrontendUrl::emailVerification(
            $notifiable->uuid,
            sha1((string) $notifiable->getEmailForVerification()),
            $query,
        );
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['email-verification'];
    }
}
