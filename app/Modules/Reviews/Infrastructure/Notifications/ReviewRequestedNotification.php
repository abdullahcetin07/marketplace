<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Infrastructure\Notifications;

use App\Core\Application\Notifications\BaseNotification;
use App\Shared\Enums\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Asks a buyer what they thought of something that arrived (ADR-087).
 *
 * MAIL ONLY, AND ONCE PER PURCHASE. The sweep records that it asked before it
 * asks, so a second invitation about the same delivered line cannot be sent —
 * see `review_requests`. v1 has no follow-up reminder deliberately: a second
 * email about the same parcel is where a service message starts reading as
 * marketing.
 *
 * **IT IS A SERVICE MESSAGE ABOUT THE RECIPIENT'S OWN ORDER, WHICH IS WHY IT MAY
 * BE SENT AT ALL** — but it sits close enough to the ETK line that the sweep
 * honours the marketing opt-out anyway rather than arguing the distinction.
 * `BaseNotification` also filters the channel by preference, so a buyer who
 * silenced mail is silenced here twice over.
 *
 * The points line is not decoration: a published review earns
 * `loyalty.earn.review` points (ADR-082), so the mail says so — an invitation
 * that names what the reader gets is the one that gets answered.
 */
final class ReviewRequestedNotification extends BaseNotification
{
    public function __construct(
        private readonly string $productTitle,
        private readonly string $url,
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
        $points = (int) settings('loyalty.earn.review', 0);

        $mail = (new MailMessage)
            ->subject(__('reviews.request.subject', ['product' => $this->productTitle]))
            ->line(__('reviews.request.intro', ['product' => $this->productTitle]));

        // Only promise points when points are actually switched on: an operator
        // who set the rate to zero has turned the incentive off, and a mail that
        // offers it anyway is a promise the platform will not keep.
        if ($points > 0 && (bool) settings('loyalty.enabled', true)) {
            $mail->line(__('reviews.request.points', ['points' => $points]));
        }

        return $mail
            ->action(__('reviews.request.action'), $this->url)
            ->line(__('reviews.request.outro'));
    }
}
