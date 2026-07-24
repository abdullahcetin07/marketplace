<?php

declare(strict_types=1);

namespace App\Modules\Activity\Application\Listeners;

use App\Modules\Activity\Application\Services\ActivityLogger;
use App\Modules\Activity\Domain\Enums\ActivityType;
use App\Modules\Identity\Domain\Events\DeviceTrusted;
use App\Modules\Identity\Domain\Events\EmailVerified;
use App\Modules\Identity\Domain\Events\PasswordChanged;
use App\Modules\Identity\Domain\Events\PasswordResetRequested;
use App\Modules\Identity\Domain\Events\SessionRevoked;
use App\Modules\Identity\Domain\Events\SuspiciousLoginDetected;
use App\Modules\Identity\Domain\Events\TwoFactorDisabled;
use App\Modules\Identity\Domain\Events\TwoFactorEnabled;
use App\Modules\Identity\Domain\Events\UserCreated;
use App\Modules\Identity\Domain\Events\UserLoggedIn;
use App\Modules\Identity\Domain\Events\UserLoggedOut;
use Illuminate\Events\Dispatcher;

/**
 * Turns Identity's domain events into activity entries.
 *
 * THIS CLASS IS THE MODULE BOUNDARY. Identity does not know Activity exists —
 * it announces what happened and stops. Activity subscribes. That is what lets
 * the layering test assert `Identity` does not use `Activity`, and it is why
 * removing the Activity module would leave Identity working with no listeners
 * rather than fatal.
 *
 * The dependency direction is deliberate: Activity may import Identity's
 * events (it is the consumer), but never the reverse.
 *
 * NOT QUEUED. An activity entry is a single insert, and queuing it would mean
 * a security page that lags behind reality — a user checking "was that me?"
 * immediately after a login must see it.
 *
 * @see docs/001_Architecture.md §4
 * @see docs/audit.md
 */
final class RecordIdentityActivity
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            UserCreated::class => 'onUserCreated',
            UserLoggedIn::class => 'onLoggedIn',
            UserLoggedOut::class => 'onLoggedOut',
            PasswordChanged::class => 'onPasswordChanged',
            PasswordResetRequested::class => 'onPasswordResetRequested',
            EmailVerified::class => 'onEmailVerified',
            TwoFactorEnabled::class => 'onTwoFactorEnabled',
            TwoFactorDisabled::class => 'onTwoFactorDisabled',
            SessionRevoked::class => 'onSessionRevoked',
            DeviceTrusted::class => 'onDeviceTrusted',
            SuspiciousLoginDetected::class => 'onSuspiciousLogin',
        ];
    }

    public function onUserCreated(UserCreated $event): void
    {
        $this->activity->log(
            type: ActivityType::ProfileUpdated,
            user: $event->userId,
            description: __('activity.account_created'),
            properties: ['type' => $event->type->value],
        );
    }

    public function onLoggedIn(UserLoggedIn $event): void
    {
        $this->activity->log(
            type: ActivityType::Login,
            user: $event->userId,
            properties: [
                'guard' => $event->guard,
                'ip' => $event->ipAddress,
                // Surfaced prominently on the security page — an unrecognised
                // device is the signal a user can actually act on.
                'new_device' => $event->newDevice,
            ],
        );
    }

    public function onLoggedOut(UserLoggedOut $event): void
    {
        $this->activity->log(
            type: ActivityType::Logout,
            user: $event->userId,
            properties: ['guard' => $event->guard, 'reason' => $event->reason],
        );
    }

    public function onPasswordChanged(PasswordChanged $event): void
    {
        $this->activity->log(
            // A reset is an account-recovery path an attacker can drive; a
            // change required knowing the old password. Different types so
            // they are distinguishable at a glance in the timeline.
            type: $event->viaReset ? ActivityType::PasswordReset : ActivityType::PasswordChanged,
            user: $event->userId,
        );
    }

    /**
     * A reset was REQUESTED — distinct from one being completed.
     *
     * A user seeing this who did not request it is the earliest warning that
     * someone is trying to take the account, and it is visible before any
     * password actually changes.
     */
    public function onPasswordResetRequested(PasswordResetRequested $event): void
    {
        $this->activity->log(
            type: ActivityType::PasswordReset,
            user: $event->userId,
            description: __('activity.password_reset_requested'),
            properties: ['guard' => $event->guard, 'ip' => $event->ipAddress, 'requested' => true],
        );
    }

    public function onEmailVerified(EmailVerified $event): void
    {
        $this->activity->log(
            type: ActivityType::EmailVerified,
            user: $event->userId,
            properties: ['guard' => $event->guard],
        );
    }

    public function onTwoFactorEnabled(TwoFactorEnabled $event): void
    {
        $this->activity->log(ActivityType::TwoFactorEnabled, $event->userId);
    }

    public function onTwoFactorDisabled(TwoFactorDisabled $event): void
    {
        $this->activity->log(
            type: ActivityType::TwoFactorDisabled,
            user: $event->userId,
            properties: ['by_administrator' => $event->byAdministrator],
        );
    }

    public function onDeviceTrusted(DeviceTrusted $event): void
    {
        $this->activity->log(
            type: ActivityType::DeviceTrusted,
            user: $event->userId,
            properties: ['device' => $event->deviceLabel],
        );
    }

    /**
     * A detected attack lands on the targeted account's OWN timeline, so a user
     * who logs in later sees "someone was trying to get in" alongside their own
     * activity. When the address has no account there is no timeline to write
     * to, but the attempt is still recorded against the email for an operator
     * reviewing a stuffing run.
     */
    public function onSuspiciousLogin(SuspiciousLoginDetected $event): void
    {
        $properties = [
            'kind' => $event->kind->value,
            'guard' => $event->guard,
            'ip' => $event->ipAddress,
            'failure_count' => $event->failureCount,
            'distinct_ips' => $event->distinctIps,
        ];

        if ($event->userId === null) {
            $this->activity->logAnonymous(ActivityType::SuspiciousLogin, $event->email, $properties);

            return;
        }

        $this->activity->log(
            type: ActivityType::SuspiciousLogin,
            user: $event->userId,
            properties: $properties,
        );
    }

    public function onSessionRevoked(SessionRevoked $event): void
    {
        $this->activity->log(
            type: ActivityType::SessionRevoked,
            user: $event->userId,
            properties: [
                'count' => $event->count(),
                'reason' => $event->reason,
                'by_administrator' => $event->revokedByUserId !== null,
            ],
        );
    }
}
