<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Channels;

use App\Modules\Notification\Domain\Contracts\PushProvider;
use App\Modules\Notification\Domain\Exceptions\ChannelNotImplemented;
use App\Shared\Enums\NotificationType;
use Illuminate\Notifications\Notification;

/**
 * Push delivery channel.
 *
 * Same shape and same reasoning as SmsChannel: the channel exists, the
 * provider does not. Binding a concrete PushProvider switches it on.
 *
 * Device tokens are NOT stored yet — that needs a mobile client to exist, and
 * a token table with no client is dead schema. `routeNotificationForPush()`
 * on the notifiable is the seam where it will attach.
 *
 * @see docs/notifications.md
 */
final class PushChannel
{
    public function __construct(private readonly ?PushProvider $provider = null) {}

    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toPush')) {
            return;
        }

        $tokens = $this->tokensFor($notifiable);

        if ($tokens === []) {
            return;
        }

        if ($this->provider === null) {
            throw ChannelNotImplemented::for(NotificationType::Push);
        }

        /** @var array<string, mixed> $payload */
        $payload = $notification->toPush($notifiable);

        $this->provider->send($tokens, $payload);
    }

    /**
     * @return array<int, string>
     */
    private function tokensFor(mixed $notifiable): array
    {
        if (! method_exists($notifiable, 'routeNotificationForPush')) {
            return [];
        }

        $tokens = $notifiable->routeNotificationForPush();

        return is_array($tokens) ? array_values(array_filter($tokens, 'is_string')) : [];
    }
}
