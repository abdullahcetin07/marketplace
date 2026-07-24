<?php

declare(strict_types=1);

namespace App\Core\Application\Notifications;

use App\Shared\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Root of every platform notification.
 *
 * PLATFORM INFRASTRUCTURE, NOT NOTIFICATION-MODULE BUSINESS LOGIC (ADR-028
 * ratification note). It lives in `app/Core` alongside `BaseAction`,
 * `BaseEvent` and the other bases every module extends — so Identity,
 * Organization and every future module depend on Core here, never on the
 * Notification module. That is what keeps the module-isolation layering test
 * honest without an exception: the Notification MODULE owns delivery
 * (preferences, the send jobs, the drivers); this base owns only the shape.
 *
 * It touches no Notification-module class: channel vocabulary is the shared
 * `NotificationType` enum, and opt-out is duck-typed on the notifiable
 * (`method_exists(... 'hasOptedOutOf')`), so Core never imports a module.
 *
 * Three things it fixes that Laravel leaves to each notification:
 *
 * 1. **Channel selection is declared, not hand-written.** A subclass lists
 *    `NotificationType` cases; `via()` maps them to Laravel channels and drops
 *    any the recipient has opted out of. Writing `via()` by hand in every
 *    notification is how one ends up ignoring preferences.
 *
 * 2. **Unimplemented channels are filtered out.** SMS and push have no
 *    provider yet. Without this, every notification declaring SMS would throw
 *    in production the day someone adds one.
 *
 * 3. **Queued by default, on the right queue.** Notifications are never worth
 *    blocking a request for, and `notifications` is the highest-priority
 *    Horizon queue precisely because a user is waiting on a password reset.
 *
 * @see App\Shared\Enums\NotificationType
 * @see docs/notifications.md
 */
abstract class BaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /**
     * Channels this notification WANTS. Override per notification.
     *
     * Database is always included by the default: it is the in-app inbox and
     * cannot be opted out of, so a user always has a record even when every
     * other channel is off or failing.
     *
     * @return array<int, NotificationType>
     */
    public function channels(): array
    {
        return [NotificationType::Database];
    }

    /**
     * Whether this is security-relevant. Security notifications IGNORE opt-out
     * preferences — a user must not be able to silence the alert that tells
     * them their password was changed.
     */
    public function isSecurityAlert(): bool
    {
        return false;
    }

    /**
     * Resolve the Laravel channel list. Called by the framework.
     *
     * @return array<int, string>
     */
    final public function via(mixed $notifiable): array
    {
        return collect($this->channels())
            ->filter(fn (NotificationType $type): bool => $this->shouldSend($type, $notifiable))
            ->map(fn (NotificationType $type): string => $type->channel())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Queue this notification lands on, derived from its highest-priority
     * channel so a mail notification does not sit behind bulk indexing.
     */
    public function viaQueues(): array
    {
        $queues = [];

        foreach ($this->channels() as $type) {
            $queues[$type->channel()] = $type->queue();
        }

        return $queues;
    }

    /**
     * A channel is used when it has a provider AND the recipient has not opted
     * out — unless this is a security alert, which overrides preferences.
     */
    protected function shouldSend(NotificationType $type, mixed $notifiable): bool
    {
        if (! $type->isImplemented()) {
            return false;
        }

        if (! $type->isOptOutable() || $this->isSecurityAlert()) {
            return true;
        }

        if (method_exists($notifiable, 'hasOptedOutOf')) {
            return ! $notifiable->hasOptedOutOf($type, static::class);
        }

        return true;
    }
}
