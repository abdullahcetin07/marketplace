<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Horizon dashboard access control and failure alerting.
 *
 * The dashboard is NOT protected by "is authenticated" alone. Job payloads
 * routinely contain customer email addresses, order contents and internal
 * identifiers; an Editor with admin-panel access has no business reading them.
 * Access requires the explicit `system.horizon.view` permission.
 */
final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        /*
        | Notify on failed jobs in production. Silence here means a broken
        | queue is discovered by a customer, not by the team.
        */
        if ($this->app->isProduction() && filled(config('services.slack.notifications.channel'))) {
            Horizon::routeSlackNotificationsTo(
                (string) config('services.slack.notifications.bot_user_oauth_token'),
                (string) config('services.slack.notifications.channel'),
            );
        }

        Horizon::night();
    }

    /**
     * Gate used by Horizon's own routes.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', static function (?User $user): bool {
            if (! $user instanceof User || ! $user->isAdmin()) {
                return false;
            }

            return $user->hasPermissionTo('system.horizon.view', 'admin');
        });
    }
}
