<?php

declare(strict_types=1);

namespace App\Modules\Loyalty;

use App\Modules\Loyalty\Application\Listeners\AwardReviewPoints;
use App\Modules\Loyalty\Application\Listeners\AwardSignupPoints;
use App\Modules\Loyalty\Domain\Contracts\LoyaltyLedgerRepositoryContract;
use App\Modules\Loyalty\Infrastructure\Repositories\LoyaltyLedgerRepository;
use App\Modules\Loyalty\Presentation\Commands\AwardPurchasePointsCommand;
use App\Shared\Enums\UserType;
use App\Shared\Support\PermissionRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Loyalty — customer points (ADR-081/082/083).
 *
 * **THE MODULE IMPORTS NOTHING.** Identity's registration and Reviews' publication
 * arrive as class-STRINGS below; the order read and the review author come from
 * Core contracts. `LayeringTest` fails the build in both directions, and the
 * strings are what let a listener react to an event whose class it may not name.
 *
 * **PHASE 1 EARNS AND DOES NOT SPEND.** There is no `LoyaltyContract` here, and
 * its absence is deliberate: redemption is ADR-084, and a port with nothing behind
 * it would invite checkout to depend on a promise nothing keeps.
 *
 * @see docs/modules/Loyalty.md
 */
final class LoyaltyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LoyaltyLedgerRepositoryContract::class, LoyaltyLedgerRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('Modules/Loyalty/migrations'));

        $this->registerPermissions();
        $this->listenForEarning();

        if ($this->app->runningInConsole()) {
            $this->commands([AwardPurchasePointsCommand::class]);
        }
    }

    /**
     * **ONE ABILITY, NOT A RESOURCE.** The generated CRUD set would produce
     * `loyalty.delete` and `loyalty.restore` — verbs an append-only ledger has no
     * operation for. The only thing anybody administers here is the rates.
     */
    private function registerPermissions(): void
    {
        PermissionRegistry::ability('loyalty.settings.manage', [UserType::Admin]);
    }

    /**
     * The two event-driven earns (ADR-081).
     *
     * **BY CLASS-STRING, THE PLATFORM'S ESTABLISHED SEAM** — the same way Inventory
     * hears Offer's stock events and Payment hears Shipping's delivery. The third
     * earn, purchase, is a date passing rather than an event, so it is a sweep.
     */
    private function listenForEarning(): void
    {
        Event::listen(
            'App\Modules\Identity\Domain\Events\UserCreated',
            [AwardSignupPoints::class, 'handle'],
        );

        Event::listen(
            'App\Modules\Reviews\Domain\Events\ReviewPublished',
            [AwardReviewPoints::class, 'handle'],
        );
    }
}
