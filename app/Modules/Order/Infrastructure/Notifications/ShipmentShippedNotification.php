<?php

declare(strict_types=1);

namespace App\Modules\Order\Infrastructure\Notifications;

use App\Core\Application\Notifications\BaseNotification;
use App\Shared\Enums\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Kargoya verildi" — the second half of the promise the confirmation makes
 * (Order.md §14).
 *
 * **IT IS PER ORDER, UNLIKE THE CONFIRMATION.** One basket becomes one e-mail
 * when it is PAID, because the shopper pressed pay once. It becomes N parcels
 * when it SHIPS, because each seller hands theirs over on their own day — and a
 * buyer waiting on two parcels wants to hear about each one as it leaves, not a
 * digest when the slowest seller finally moves.
 *
 * **THE TRACKING NUMBER IS A LINK WHEN THE CARRIER HAS ONE**, and plain text
 * when it does not. A number the customer has to copy into a search engine is
 * the version of this e-mail that generates the support ticket it was meant to
 * prevent.
 *
 * **IT SAYS WHO SENT IT.** On a multi-seller basket "your order has shipped"
 * without a name answers the wrong question: the buyer knows something shipped,
 * and wants to know which of the two it was.
 */
final class ShipmentShippedNotification extends BaseNotification
{
    public function __construct(
        private readonly string $orderNumber,
        private readonly string $seller,
        private readonly string $carrier,
        private readonly string $trackingNumber,
        private readonly ?string $trackingUrl,
        private readonly string $ordersUrl,
    ) {}

    /**
     * @return array<int, NotificationType>
     */
    public function channels(): array
    {
        return [NotificationType::Mail, NotificationType::Database];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('order.shipped.subject', ['number' => $this->orderNumber]))
            ->greeting(__('order.shipped.greeting'))
            ->line(__('order.shipped.intro', [
                'number' => $this->orderNumber,
                'seller' => $this->seller,
            ]))
            ->line(__('order.shipped.carrier', ['carrier' => $this->carrier]))
            ->line(__('order.shipped.tracking', ['number' => $this->trackingNumber]));

        // The carrier's own page when there is one; otherwise the buyer's order
        // list, which is never a dead end.
        return $mail
            ->action(
                $this->trackingUrl === null
                    ? __('order.shipped.action_orders')
                    : __('order.shipped.action_track'),
                $this->trackingUrl ?? $this->ordersUrl,
            )
            ->line(__('order.shipped.outro'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'order_number' => $this->orderNumber,
            'seller' => $this->seller,
            'carrier' => $this->carrier,
            'tracking_number' => $this->trackingNumber,
        ];
    }
}
