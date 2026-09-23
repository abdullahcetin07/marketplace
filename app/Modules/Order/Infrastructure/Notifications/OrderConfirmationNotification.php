<?php

declare(strict_types=1);

namespace App\Modules\Order\Infrastructure\Notifications;

use App\Core\Application\Notifications\BaseNotification;
use App\Shared\Enums\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * What the customer gets in writing when their money arrives (Order.md §14).
 *
 * **UNTIL 2026-09-23 THEY GOT NOTHING.** A shopper paid and the platform said
 * nothing at all: no order number, no list of what they bought, no total. The
 * first e-mail about a purchase was the review invitation, days later, after the
 * parcel had already arrived. Everything before it was an account e-mail.
 *
 * **ONE E-MAIL FOR ONE PURCHASE, WITH A BLOCK PER SELLER.** The basket split into
 * N orders (ADR-052) and each one is its own contract with its own seller and its
 * own number — but the shopper pressed pay once, and three e-mails in one minute
 * reads as a system malfunctioning. So the split is shown rather than sent: each
 * seller's order is named, numbered and totalled on its own, under a single grand
 * total that matches the card statement.
 *
 * **EVERY FIGURE IS THE FROZEN ONE** (ADR-053). The lines carry the price, title
 * and KDV the customer agreed to; asking the Catalog or the Offer what they cost
 * now would print a receipt that disagrees with the charge.
 *
 * **IT PROMISES ONLY WHAT THE PLATFORM DOES.** The closing line pointed the
 * buyer at their account rather than saying "we will write again when it ships",
 * because there is no shipping e-mail yet (Order.md §14). A confirmation that
 * promises a second message the platform never sends is worse than the silence
 * it replaced — it teaches the customer to wait for nothing.
 *
 * **NOT A LEGAL CONTRACT DOCUMENT.** It is the commercial confirmation a buyer
 * needs to know what they bought and to quote a number to support. The Mesafeli
 * Sözleşmeler ön bilgilendirme formu and the contract itself are a separate piece
 * of work, recorded in Order.md §14 as outstanding.
 */
final class OrderConfirmationNotification extends BaseNotification
{
    /**
     * @param array<int, array{number: string, seller: string, total: string, lines: array<int, array{title: string, quantity: int, total: string}>}> $orders
     */
    public function __construct(
        private readonly array $orders,
        private readonly string $grandTotal,
        private readonly string $shippingAddress,
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
            ->subject(__('order.confirmation.subject'))
            ->greeting(__('order.confirmation.greeting'))
            ->line(__('order.confirmation.intro'));

        foreach ($this->orders as $order) {
            /*
            | THE SELLER IS NAMED ON EVERY BLOCK, not once at the top. A buyer
            | chasing a parcel needs to know WHOSE it is, and on a multi-seller
            | basket the answer differs per order.
            */
            $mail->line('**'.__('order.confirmation.order_line', [
                'number' => $order['number'],
                'seller' => $order['seller'],
            ]).'**');

            foreach ($order['lines'] as $line) {
                $mail->line('• '.$line['quantity'].' × '.$line['title'].' — '.$line['total']);
            }

            $mail->line(__('order.confirmation.order_total', ['total' => $order['total']]));
        }

        return $mail
            ->line('**'.__('order.confirmation.grand_total', ['total' => $this->grandTotal]).'**')
            ->line(__('order.confirmation.shipping_to', ['address' => $this->shippingAddress]))
            ->action(__('order.confirmation.action'), $this->ordersUrl)
            ->line(__('order.confirmation.outro'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'orders' => array_map(
                static fn (array $order): array => ['number' => $order['number'], 'seller' => $order['seller']],
                $this->orders,
            ),
            'grand_total' => $this->grandTotal,
        ];
    }
}
