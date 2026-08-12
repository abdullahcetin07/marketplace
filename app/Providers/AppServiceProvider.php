<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Domain\Contracts\InvitationTokenizerContract;
use App\Core\Domain\Contracts\OtpStoreContract;
use App\Core\Infrastructure\Invitations\Sha256InvitationTokenizer;
use App\Core\Infrastructure\Otp\CacheOtpStore;
use App\Shared\Enums\UserType;
use App\Shared\Rules\StrongPassword;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

/**
 * Global application configuration applied at boot.
 *
 * The performance and correctness settings here are the ones that are almost
 * impossible to retrofit later — turning strict mode on in a mature codebase
 * means fixing hundreds of latent N+1 queries at once. Enabling it in Sprint 0
 * means each one is caught by whoever writes it, on the day they write it.
 *
 * @see docs/performance.md
 */
final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
        | CarbonImmutable everywhere. A mutable date is a shared-mutable-state
        | bug waiting to happen: `$order->created_at->addDays(7)` silently
        | mutates the model's attribute with the default Carbon.
        */
        Date::use(CarbonImmutable::class);

        /*
        | The OTP store is a shared Core primitive (ADR-026) — bound here, not
        | in a module, so any module can consume it without importing Identity.
        */
        $this->app->singleton(OtpStoreContract::class, CacheOtpStore::class);

        /*
        | The invitation tokenizer is Core infrastructure (ADR-031) — any module
        | that grows a team reuses it, so it is bound here rather than in
        | Organization, the first consumer.
        */
        $this->app->singleton(InvitationTokenizerContract::class, Sha256InvitationTokenizer::class);
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureFactories();
        $this->configureDatabase();
        $this->configurePasswords();
        $this->configureRateLimiting();
        $this->configureUrls();
        $this->configureFilamentActionRoutes();
    }

    /**
     * Let a seller or an admin download their own import failure report.
     *
     * **FILAMENT GUARDS ITS IMPORT ROUTES WITH A BARE `auth`, AND A BARE `auth`
     * IS THE CUSTOMER GUARD HERE.** `ActionsServiceProvider` registers
     * `filament.actions` as `['web', 'auth']`, which resolves
     * `auth.defaults.guard` — `customer` on this platform (three guards, ADR-011).
     * So the "hata raporunu indir" button in every import notification bounced a
     * signed-in seller AND a signed-in admin to the login page, and the failure
     * report the catalogue import (ADR-074) and the offer feed (ADR-076) both
     * advertise was unreachable by the only two people it is written for.
     *
     * Naming the three guards fixes it without touching the vendor route: the
     * `auth` middleware tries each in turn and calls `shouldUse()` on the one
     * that answers, so the controller's own `$import->user()->is(auth()->user())`
     * check — which decides whose report you may read — then compares against the
     * right person rather than against null.
     */
    private function configureFilamentActionRoutes(): void
    {
        app(Router::class)->middlewareGroup('filament.actions', [
            'web',
            'auth:'.implode(',', [UserType::Admin->value, UserType::Seller->value, UserType::Customer->value]),
        ]);
    }

    /**
     * Teach Eloquent where module factories live.
     *
     * A module's factory sits with its module, not in the shared factory
     * directory (see database/Modules/README.md):
     *
     *   App\Modules\Store\Domain\Models\Store
     *     -> Database\Modules\Store\Factories\StoreFactory
     *
     * Laravel's default resolver strips the app namespace and prefixes
     * `Database\Factories\`, which would look for
     * `Database\Factories\Modules\Store\Domain\Models\StoreFactory` — a path
     * that does not exist. Registered once here rather than as a `newFactory()`
     * on all ~28 models, so a new module model needs no boilerplate.
     *
     * `app/Models` (User, Admin, Seller, Customer) keeps Laravel's own
     * convention, which is exactly where those factories already live.
     */
    private function configureFactories(): void
    {
        Factory::guessFactoryNamesUsing(static function (string $modelName): string {
            if (str_starts_with($modelName, 'App\\Modules\\')) {
                $segments = explode('\\', substr($modelName, strlen('App\\Modules\\')));
                $module = $segments[0];

                return 'Database\\Modules\\'.$module.'\\Factories\\'.class_basename($modelName).'Factory';
            }

            return 'Database\\Factories\\'.class_basename($modelName).'Factory';
        });
    }

    /**
     * Eloquent strict mode.
     */
    private function configureModels(): void
    {
        /*
        | shouldBeStrict() switches on three things at once:
        |
        |  preventLazyLoading        — accessing an unloaded relation throws
        |                              instead of firing a query. This is THE
        |                              N+1 defence: a listing page that would
        |                              have quietly issued 200 queries now
        |                              fails in development.
        |  preventSilentlyDiscarding— assigning an unfillable attribute throws
        |                              instead of being dropped. Catches the
        |                              class of bug where a form field appears
        |                              to save and does not.
        |  preventAccessingMissing  — reading an attribute that was not
        |                              selected throws instead of returning
        |                              null. Catches `select('id')` followed by
        |                              `->name` returning null forever.
        |
        | In production it is downgraded: throwing on a lazy load would turn a
        | missed eager-load into a 500 for a real customer. Instead the offence
        | is logged so it is fixed on the next deploy, not experienced by the
        | user. Development and CI keep the hard failure.
        */
        Model::shouldBeStrict(! $this->app->isProduction());

        if ($this->app->isProduction()) {
            Model::handleLazyLoadingViolationUsing(
                static function (Model $model, string $relation): void {
                    Log::channel('errors')->warning('Lazy loading violation', [
                        'model' => $model::class,
                        'relation' => $relation,
                        'correlation_id' => correlation_id(),
                    ]);
                },
            );

            Model::handleMissingAttributeViolationUsing(
                static function (Model $model, string $attribute): void {
                    Log::channel('errors')->warning('Missing attribute access', [
                        'model' => $model::class,
                        'attribute' => $attribute,
                    ]);
                },
            );
        }
    }

    private function configureDatabase(): void
    {
        /*
        | Fail loudly on a slow query in development. 500ms on a marketplace
        | listing page is already too slow; catching it here beats finding it
        | in an APM dashboard after launch.
        */
        if (! $this->app->isProduction()) {
            DB::whenQueryingForLongerThan(500, static function ($connection, $event): void {
                Log::channel('daily')->warning('Slow query', [
                    'sql' => $event->sql ?? null,
                    'time_ms' => $event->time ?? null,
                ]);
            });
        }

        /*
        | Cumulative query-time budget per request. A page that spends more
        | than 2 seconds in the database is broken regardless of which
        | individual query is slowest.
        */
        DB::whenQueryingForLongerThan(2000, static function ($connection): void {
            Log::channel('errors')->warning('Request exceeded database time budget', [
                'connection' => $connection->getName(),
                'correlation_id' => correlation_id(),
            ]);
        });
    }

    private function configurePasswords(): void
    {
        Password::defaults(static fn (): Password => StrongPassword::default());
    }

    /**
     * Named rate limiters, applied by the route groups in routes/api.php.
     *
     * Limits are per-user when authenticated and per-IP otherwise, so one
     * user behind a shared NAT cannot exhaust everyone else's budget.
     */
    private function configureRateLimiting(): void
    {
        $limits = (array) config('marketplace.security.rate_limits');

        RateLimiter::for('api', static fn (Request $request): Limit => Limit::perMinute($limits['api'] ?? 60)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        /*
        | Login, registration and password reset. Deliberately harsh — this is
        | the credential-stuffing surface. Keyed on email AND ip so an attacker
        | cannot rotate IPs to brute-force one account, nor rotate accounts
        | from one IP.
        */
        RateLimiter::for('auth', static fn (Request $request): array => [
            Limit::perMinute($limits['auth'] ?? 5)->by('email:'.mb_strtolower((string) $request->input('email'))),
            Limit::perMinute(($limits['auth'] ?? 5) * 4)->by('ip:'.$request->ip()),
        ]);

        /*
        | Search hits OpenSearch on every call and is the cheapest way to load
        | the cluster from outside.
        */
        RateLimiter::for('search', static fn (Request $request): Limit => Limit::perMinute($limits['search'] ?? 30)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        /*
        | Panels are authenticated staff traffic; the limit exists to bound a
        | runaway script, not to police normal use.
        */
        RateLimiter::for('panel', static fn (Request $request): Limit => Limit::perMinute($limits['panel'] ?? 120)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        /*
        | The public storefront (ADR-034): anonymous browsing traffic. Keyed on
        | IP only — there is no authenticated user — and generous, since a store
        | page is meant to be hit freely; the limit only bounds scraping.
        */
        RateLimiter::for('storefront', static fn (Request $request): Limit => Limit::perMinute($limits['storefront'] ?? 300)
            ->by('storefront:'.$request->ip()));
    }

    private function configureUrls(): void
    {
        /*
        | Force HTTPS outside local development. Without this, a signed URL
        | generated behind a TLS-terminating proxy is signed as http:// and
        | fails verification when the browser follows it over https://.
        */
        if ($this->app->environment(['production', 'staging'])) {
            URL::forceScheme('https');
        }
    }
}
