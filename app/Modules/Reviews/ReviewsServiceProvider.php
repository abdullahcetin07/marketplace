<?php

declare(strict_types=1);

namespace App\Modules\Reviews;

use App\Modules\Reviews\Domain\Contracts\ReviewRepositoryContract;
use App\Modules\Reviews\Domain\Models\Review;
use App\Modules\Reviews\Infrastructure\Repositories\ReviewRepository;
use App\Modules\Reviews\Presentation\Policies\ReviewPolicy;
use App\Shared\Enums\UserType;
use App\Shared\Support\PermissionRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Reviews module wiring.
 *
 * **THE FIRST MODULE BUILT TO CARRY USER-GENERATED CONTENT ABOUT THE CATALOGUE**,
 * and the first whose records a stranger reads on a public page. A review is one
 * buyer's rating (1–5) + optional text + optional photos of a product they were
 * DELIVERED, published only after a moderator approves it (ADR-068).
 *
 * IT IS ABOUT THE PRODUCT, TAGGED WITH THE SELLER (ADR-066). The catalogue is
 * shared — one product, many sellers (ADR-037) — so one product accumulates every
 * seller's buyers' reviews, the rating average is the PRODUCT's, and "bu
 * satıcıdan alanlar ne demiş" is a filter on that one set rather than a second
 * set. The tag is copied from the delivered order line and never typed by the
 * buyer, so nobody can praise or damn a seller for a purchase that was not theirs.
 *
 * IT IMPORTS NO MODULE. Catalog and Order are read through Core contracts;
 * photos ride the shared `App\Shared\Traits\HasMedia` trait rather than the Media
 * module; store names come from `StoreQueryContract`. `LayeringTest` fails the
 * build on any import, both directions.
 *
 * WHAT IT IS NOT: a seller rating (a merchant score is a future module),
 * Questions & Answers (a separate module, owner decision 2026-08-06), a comment
 * thread, helpful-votes, or a media host.
 *
 * @see docs/modules/Reviews.md
 */
final class ReviewsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReviewRepositoryContract::class, ReviewRepository::class);

        $this->registerPermissions();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('Modules/Reviews/migrations'));

        Gate::policy(Review::class, ReviewPolicy::class);
    }

    /**
     * Permissions are DERIVED from a registration, never hand-listed.
     *
     * **`review` IS READ-ONLY PLUS ONE VERB, AND THE VERB IS THE WHOLE MODULE'S
     * POINT.** `view_any`/`view` open the moderation queue; `review.moderate` is
     * the approve/reject lever. There is deliberately no `review.create` — a
     * review is written by a CUSTOMER, authorised by having been delivered the
     * goods (ADR-067), and that is a purchase record rather than a privilege
     * somebody can be granted.
     *
     * **AND NO SELLER ABILITY AT ALL** (ADR-068). Not to approve, not to hide,
     * not to reject. The party a review judges does not get a lever over it, and
     * the strongest way to say so is that the ability does not exist.
     *
     * Editor gets these alongside Admin in `RolePermissionSeeder` — review text
     * and photos are content, which is the Editor role's business.
     */
    private function registerPermissions(): void
    {
        PermissionRegistry::resource('review', [UserType::Admin]);
        PermissionRegistry::ability('review.moderate', [UserType::Admin]);
    }
}
