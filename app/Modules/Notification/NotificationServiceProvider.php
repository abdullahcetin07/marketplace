<?php

declare(strict_types=1);

namespace App\Modules\Notification;

use App\Modules\Notification\Domain\Models\NotificationPreference;
use App\Modules\Notification\Infrastructure\Channels\PushChannel;
use App\Modules\Notification\Infrastructure\Channels\SmsChannel;
use App\Modules\Notification\Presentation\Policies\NotificationPreferencePolicy;
use App\Shared\Enums\UserType;
use App\Shared\Support\PermissionRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Notification module wiring.
 *
 * SPRINT 1 SHIPS INFRASTRUCTURE, NOT PROVIDERS. The channels are registered
 * and routable; `SmsProvider` and `PushProvider` are deliberately UNBOUND, so
 * a notification sent over those channels fails loudly rather than vanishing.
 *
 * Switching SMS on later is exactly this, and nothing else:
 *
 *     $this->app->bind(SmsProvider::class, NetgsmProvider::class);
 *
 * @see docs/notifications.md
 */
final class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
        | Channels resolve their provider as an OPTIONAL constructor argument.
        | Passing null when nothing is bound is what lets the channel throw a
        | typed ChannelNotImplemented instead of a container resolution error
        | that says nothing useful.
        */
        $this->app->singleton(SmsChannel::class, fn ($app): SmsChannel => new SmsChannel(
            $app->bound(Domain\Contracts\SmsProvider::class)
                ? $app->make(Domain\Contracts\SmsProvider::class)
                : null,
        ));

        $this->app->singleton(PushChannel::class, fn ($app): PushChannel => new PushChannel(
            $app->bound(Domain\Contracts\PushProvider::class)
                ? $app->make(Domain\Contracts\PushProvider::class)
                : null,
        ));

        // Every actor manages their own preferences; ownership in the policy
        // confines that to their own rows.
        PermissionRegistry::resource('notification_preference', [
            UserType::Admin,
            UserType::Seller,
            UserType::Customer,
        ]);
        PermissionRegistry::ability('notification.send_broadcast', [UserType::Admin]);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('Modules/Notification/migrations'));

        Gate::policy(NotificationPreference::class, NotificationPreferencePolicy::class);
    }
}
