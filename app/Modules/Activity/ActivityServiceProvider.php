<?php

declare(strict_types=1);

namespace App\Modules\Activity;

use App\Modules\Activity\Application\Listeners\RecordIdentityActivity;
use App\Modules\Activity\Application\Services\ActivityLogger;
use App\Modules\Activity\Domain\Models\ActivityEntry;
use App\Modules\Activity\Presentation\Policies\ActivityEntryPolicy;
use App\Shared\Enums\UserType;
use App\Shared\Support\PermissionRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Activity module wiring.
 *
 * @see docs/audit.md
 */
final class ActivityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ActivityLogger::class);

        /*
        | All three actor types hold `activity.view` — every user can see their
        | OWN timeline. ActivityEntryPolicy::owns() confines non-admins to it.
        | Without the ownership check this would expose every user's login
        | history to every other user.
        */
        PermissionRegistry::ability('activity.view_any', [UserType::Admin]);
        PermissionRegistry::ability('activity.view', [
            UserType::Admin,
            UserType::Seller,
            UserType::Customer,
        ]);
        PermissionRegistry::ability('activity.export', [UserType::Admin]);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('Modules/Activity/migrations'));

        Gate::policy(ActivityEntry::class, ActivityEntryPolicy::class);

        /*
        | Activity subscribes to Identity's events rather than Identity calling
        | into Activity. This class IS the module boundary — see its docblock
        | and docs/001_Architecture.md §4.
        */
        Event::subscribe(RecordIdentityActivity::class);
    }
}
