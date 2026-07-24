<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Listeners;

use App\Modules\Identity\Domain\Contracts\UserRepositoryContract;
use App\Modules\Identity\Domain\Events\SuspiciousLoginDetected;
use App\Modules\Identity\Infrastructure\Notifications\SecurityAlertNotification;
use App\Modules\Identity\Infrastructure\Notifications\SuspiciousLoginNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Notifies both the account owner and the administrators when an address comes
 * under attack (Q6 — "notify both").
 *
 * Identity subscribing to its OWN event, on purpose: the notification is a
 * reaction with two audiences and an admin lookup, and putting it behind the
 * event keeps AuthService a dispatcher rather than a mailer. The event already
 * fans out to Audit (forensic entry) and Activity (timeline) — this is the
 * third listener, and none of them knows about the others.
 *
 * Throttling already happened at the source: AuthService raises the event at
 * most once per cooldown, so this fires once per attack window, not once per
 * failed attempt.
 *
 * @see App\Modules\Identity\Application\Services\AuthService::flagIfUnderAttack()
 */
final class NotifyOnSuspiciousLogin
{
    public function __construct(private readonly UserRepositoryContract $users) {}

    public function handle(SuspiciousLoginDetected $event): void
    {
        // The owner — only when the targeted address is a real account. A
        // stuffing run against an address that was never registered has nobody
        // to warn, but the admins still hear about it below.
        if ($event->userUuid !== null) {
            $user = $this->users->findByUuid($event->userUuid);

            $user?->notify(new SuspiciousLoginNotification(
                $event->failureCount,
                $event->distinctIps,
            ));
        }

        $admins = $this->users->securityAlertRecipients();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new SecurityAlertNotification(
                $event->email,
                $event->failureCount,
                $event->distinctIps,
            ));
        }
    }
}
