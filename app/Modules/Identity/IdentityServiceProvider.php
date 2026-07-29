<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Seller;
use App\Models\User;
use App\Modules\Identity\Application\Listeners\NotifyOnSuspiciousLogin;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryContract;
use App\Modules\Identity\Domain\Contracts\LoginAttemptRepositoryContract;
use App\Modules\Identity\Domain\Contracts\SessionRepositoryContract;
use App\Modules\Identity\Domain\Contracts\UserRepositoryContract;
use App\Modules\Identity\Domain\Events\SuspiciousLoginDetected;
use App\Modules\Identity\Domain\Models\UserDevice;
use App\Modules\Identity\Domain\Models\UserSession;
use App\Modules\Identity\Infrastructure\Observers\UserObserver;
use App\Modules\Identity\Infrastructure\Repositories\DeviceRepository;
use App\Modules\Identity\Infrastructure\Repositories\LoginAttemptRepository;
use App\Modules\Identity\Domain\Contracts\TotpProviderContract;
use App\Modules\Identity\Infrastructure\Repositories\SessionRepository;
use App\Modules\Identity\Infrastructure\Repositories\UserRepository;
use App\Modules\Identity\Infrastructure\Totp\Google2FaTotpProvider;
use App\Modules\Identity\Presentation\Policies\DevicePolicy;
use App\Modules\Identity\Presentation\Policies\UserPolicy;
use App\Modules\Identity\Presentation\Policies\UserSessionPolicy;
use App\Shared\Enums\UserType;
use App\Shared\Support\PermissionRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Identity module wiring.
 *
 * SCOPE NOTE: this module owns everything AROUND identity — sessions, devices,
 * login history, the authentication flow. It does not own `App\Models\User`
 * itself, which stays in app/Models because Core (BasePolicy) and Shared
 * (HasCreator, HasUpdater) reference it. Moving it here would make Core depend
 * on a module and fail the layering test. @see docs/authentication.md
 *
 * @see docs/authentication.md
 */
final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
        | Repository bindings (001_Architecture §4). Services and actions
        | type-hint the CONTRACT, never the concrete repository, so every one
        | of them stays unit-testable against a fake.
        */
        $this->app->singleton(UserRepositoryContract::class, UserRepository::class);
        $this->app->singleton(SessionRepositoryContract::class, SessionRepository::class);
        $this->app->singleton(DeviceRepositoryContract::class, DeviceRepository::class);
        $this->app->singleton(LoginAttemptRepositoryContract::class, LoginAttemptRepository::class);

        // The OTP store is a shared Core primitive (ADR-026), bound in
        // AppServiceProvider — Identity only consumes it.

        $this->registerTotp();
        $this->registerPermissions();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('Modules/Identity/migrations'));

        /*
        | Registered on every subclass as well as the base. Eloquent resolves
        | observers per concrete class, so registering only User would mean an
        | Admin update fired nothing.
        */
        foreach ([User::class, Admin::class, Seller::class, Customer::class] as $model) {
            $model::observe(UserObserver::class);
        }

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Admin::class, UserPolicy::class);
        Gate::policy(Seller::class, UserPolicy::class);
        Gate::policy(Customer::class, UserPolicy::class);
        Gate::policy(UserSession::class, UserSessionPolicy::class);
        // Devices got their own policy once they got their own endpoints.
        Gate::policy(UserDevice::class, DevicePolicy::class);

        /*
        | Notify the owner and the administrators when an address comes under
        | attack (Q6). A within-module subscription: Identity reacts to its own
        | event so AuthService stays a dispatcher, not a mailer. Audit and
        | Activity subscribe to the same event independently.
        */
        Event::listen(SuspiciousLoginDetected::class, NotifyOnSuspiciousLogin::class);
    }

    /**
     * Bind the TOTP algorithm behind its port (ADR-026), so nothing depends on
     * Google2FA directly. Swapping the implementation is a change here alone.
     */
    private function registerTotp(): void
    {
        $this->app->singleton(TotpProviderContract::class, Google2FaTotpProvider::class);
    }

    /**
     * Permissions are DERIVED from a resource registration, never hand-listed.
     *
     * Sellers and customers hold `session.*` for their OWN sessions — the
     * ownership check in UserSessionPolicy is what confines that. Admins hold
     * `user.*` platform-wide.
     */
    private function registerPermissions(): void
    {
        PermissionRegistry::resource('user', [UserType::Admin]);
        PermissionRegistry::resource('session', [UserType::Admin, UserType::Seller, UserType::Customer]);
        // Devices are personal to every actor type; DevicePolicy::owns() confines
        // each holder to their own.
        PermissionRegistry::resource('device', [UserType::Admin, UserType::Seller, UserType::Customer]);

        // Non-CRUD abilities. Impersonation is separated from `user.update`
        // deliberately: being able to edit an account and being able to BECOME
        // it are very different powers, and only the latter needs a paper
        // trail on every use.
        PermissionRegistry::ability('user.impersonate', [UserType::Admin]);
        PermissionRegistry::ability('user.reset_password', [UserType::Admin]);
        PermissionRegistry::ability('user.disable_two_factor', [UserType::Admin]);
        PermissionRegistry::ability('user.view_login_history', [UserType::Admin]);
        PermissionRegistry::ability('user.assign_roles', [UserType::Admin]);

        /*
        | The three account AREAS of the admin panel, each its own ability.
        |
        | `user.view_any` cannot express the distinction: it is one grant that
        | opens every account of every type. Provisioning colleagues and
        | granting them staff roles ("my team") is a different job from
        | answering a merchant's or a shopper's ticket, and the roles that do
        | those jobs are different people. Support gets the two oversight
        | areas and not the staff one — a helpdesk does not hire.
        |
        | @see App\Modules\Identity\Presentation\Filament\Resources\AccountResource
        */
        PermissionRegistry::ability('user.manage_staff', [UserType::Admin]);
        PermissionRegistry::ability('user.oversee_sellers', [UserType::Admin]);
        PermissionRegistry::ability('user.oversee_customers', [UserType::Admin]);

        // Who receives platform security alerts (Q6). A first-level
        // authorization gate, deliberately NOT part of an admin's broad grant:
        // it is assigned to the security responders, so alerting scales to
        // hundreds of admins without notifying all of them. When the
        // Notification module lands, per-user preferences sit BEHIND this gate,
        // never replace it. @see docs/notifications.md backlog.
        PermissionRegistry::ability('security.receive_alerts', [UserType::Admin]);
    }
}
