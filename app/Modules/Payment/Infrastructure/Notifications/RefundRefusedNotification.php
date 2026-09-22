<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Notifications;

use App\Core\Application\Notifications\BaseNotification;
use App\Shared\Enums\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells whoever runs the platform that the provider would not fund a refund.
 *
 * **IT GOES TO AN OPERATOR, NOT TO A CUSTOMER OR A SELLER**, so it carries the
 * provider's own words — "Net bakiyeniz yetersiz" is the whole diagnosis, and
 * sanitising it would leave the reader guessing at exactly the moment they need
 * to act.
 *
 * **MAIL ONLY.** The recipient is an address from configuration rather than a
 * user row (Payment may not reach Identity), so there is no record to write a
 * database notification against.
 *
 * **IT SURVIVES THE ROLLBACK ON PURPOSE.** The refusal throws, the transaction
 * unwinds, and the order is left exactly as it was — but the fact that somebody
 * tried and could not must outlive that, or the only trace is a log line nobody
 * reads.
 */
final class RefundRefusedNotification extends BaseNotification
{
    public function __construct(
        private readonly string $paymentUuid,
        private readonly string $amount,
        private readonly string $currency,
        private readonly ?string $detail,
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
        return (new MailMessage)
            ->subject('İade reddedildi — ödeme sağlayıcısı karşılamadı')
            ->line('Bir iade talebi ödeme sağlayıcısı tarafından reddedildi. Sipariş DEĞİŞMEDİ: para hareketi olmadı, satıcının işlemi tamamlanamadı.')
            ->line('Ödeme: '.$this->paymentUuid)
            ->line('Tutar: '.$this->amount.' '.$this->currency)
            ->line('Sağlayıcının yanıtı: '.($this->detail ?? 'belirtilmedi'))
            ->line('En sık sebep, sağlayıcıdaki net bakiyenin iadeyi karşılamaya yetmemesidir. Bakiye tamamlandığında işlem tekrar denenebilir.');
    }
}
