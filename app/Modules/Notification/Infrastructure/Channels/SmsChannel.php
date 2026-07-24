<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Channels;

use App\Modules\Notification\Domain\Contracts\SmsProvider;
use App\Modules\Notification\Domain\Exceptions\ChannelNotImplemented;
use App\Shared\Enums\NotificationType;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * SMS delivery channel.
 *
 * Sprint 1 wires the channel but ships NO PROVIDER. Binding a concrete
 * SmsProvider is all that is needed to switch it on — nothing else in the
 * platform changes, because notifications already declare `via()` and route
 * here.
 *
 * WHY IT FAILS RATHER THAN NO-OPS: a channel that silently discards messages
 * is the worst outcome. Someone eventually ships an SMS notification, sees no
 * error, and assumes it worked. Throwing means the job fails, lands in
 * `failed_jobs`, and is visible in Horizon.
 *
 * The one exception is `sms.enabled = false`, which is a deliberate operator
 * decision and logs at debug instead.
 *
 * @see App\Shared\Enums\NotificationType::channel()
 * @see docs/notifications.md
 */
final class SmsChannel
{
    public function __construct(private readonly ?SmsProvider $provider = null) {}

    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSms')) {
            return;
        }

        // Operator switched SMS off — not an error.
        if (settings()->boolean('sms.enabled') === false) {
            Log::channel('daily')->debug('SMS notification skipped: channel disabled', [
                'notification' => $notification::class,
            ]);

            return;
        }

        $recipient = $this->recipientFor($notifiable);

        if ($recipient === null) {
            // No phone number is a data problem, not an outage. Skip quietly;
            // the notification's other channels still deliver.
            return;
        }

        if ($this->provider === null) {
            throw ChannelNotImplemented::for(NotificationType::Sms);
        }

        $this->provider->send($recipient, (string) $notification->toSms($notifiable));
    }

    /**
     * E.164 destination. Prefers an explicit routeNotificationForSms(), falling
     * back to the user's phone column.
     */
    private function recipientFor(mixed $notifiable): ?string
    {
        if (method_exists($notifiable, 'routeNotificationForSms')) {
            $route = $notifiable->routeNotificationForSms();

            return is_string($route) && $route !== '' ? $route : null;
        }

        $phone = $notifiable->phone ?? null;

        return is_string($phone) && $phone !== '' ? $phone : null;
    }
}
