<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\StoreQueryContract;
use App\Modules\Offer\Domain\Contracts\OfferRepositoryContract;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Models\Offer;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Offer persistence — the guarantees the database itself makes
|--------------------------------------------------------------------------
|
| The one that matters is §3.2: ONE live offer per (org, variant). The
| repository's check gives a seller a readable error; the partial unique index
| is what holds under a race the check cannot see. Both are tested, because
| testing only the first would leave the real guarantee unverified.
|
| Also pinned: the tenancy vocabulary the seller panel will read (ADR-030), and
| the two contract additions this phase makes.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

it('refuses a second live offer for the same org and variant', function (): void {
    Offer::factory()->forOrganization(7, 'org-uuid')->forVariant('v-1', 'p-1')->create();

    // The database, not the application, is what makes this impossible.
    expect(fn () => Offer::factory()->forOrganization(7, 'org-uuid')->forVariant('v-1', 'p-1')->create())
        ->toThrow(QueryException::class);
});

it('lets a different seller offer the same variant — the whole point', function (): void {
    Offer::factory()->forOrganization(7, 'org-a')->forVariant('v-1', 'p-1')->create();
    $competitor = Offer::factory()->forOrganization(8, 'org-b')->forVariant('v-1', 'p-1')->create();

    // One product, many offers (ADR-042). If this ever failed, the marketplace
    // would be a shop.
    expect($competitor->exists)->toBeTrue()
        ->and(Offer::query()->forVariant('v-1')->count())->toBe(2);
});

it('lets a seller re-list a variant they withdrew', function (): void {
    $withdrawn = Offer::factory()->forOrganization(7, 'org-a')->forVariant('v-1', 'p-1')
        ->status(OfferStatus::Withdrawn)->create();
    $withdrawn->delete();

    $fresh = Offer::factory()->forOrganization(7, 'org-a')->forVariant('v-1', 'p-1')->create();

    // The reason the unique index is partial rather than plain: a withdrawal is
    // not a permanent ban on selling the thing again.
    expect($fresh->exists)->toBeTrue();
});

it('finds the offer that would collide, and none when the seller is free', function (): void {
    $repository = app(OfferRepositoryContract::class);

    $existing = Offer::factory()->forOrganization(7, 'org-a')->forVariant('v-1', 'p-1')->paused()->create();

    // Paused still blocks — a seller with a paused offer re-prices it rather
    // than listing a second one.
    expect($repository->duplicateFor(7, 'v-1')?->uuid)->toBe($existing->uuid)
        ->and($repository->duplicateFor(7, 'v-other'))->toBeNull()
        ->and($repository->duplicateFor(99, 'v-1'))->toBeNull();
});

it('scopes a seller to their own organizations, and gives a member of nothing nothing', function (): void {
    $repository = app(OfferRepositoryContract::class);

    $mine = Offer::factory()->forOrganization(7, 'org-a')->forVariant('v-1', 'p-1')->create();
    $theirs = Offer::factory()->forOrganization(8, 'org-b')->forVariant('v-2', 'p-1')->create();

    expect($repository->forOrganizations([7])->pluck('uuid')->all())->toBe([$mine->uuid])
        ->and($repository->forOrganizations([7])->pluck('uuid')->all())->not->toContain($theirs->uuid)
        // The empty case is stated because "no memberships" must mean "no rows",
        // never "no filter".
        ->and($repository->forOrganizations([])->all())->toBe([]);
});

it('separates offers paused by a cascade from ones the seller paused', function (): void {
    $repository = app(OfferRepositoryContract::class);

    $bySeller = Offer::factory()->forVariant('v-1', 'p-1')->paused()->create(['paused_by_cascade' => false]);
    $byCascade = Offer::factory()->forVariant('v-2', 'p-1')->paused()->create(['paused_by_cascade' => true]);

    // §3.5 — re-publishing a product must reactivate exactly what its archiving
    // paused, and leave a seller's own decision alone.
    expect($repository->cascadePausedForProduct('p-1')->pluck('uuid')->all())->toBe([$byCascade->uuid])
        ->and($repository->cascadePausedForProduct('p-1')->pluck('uuid')->all())
        ->not->toContain($bySeller->uuid);
});

it('tells Offer which live stores an organization has — the Store-required addition', function (): void {
    $mine = Organization::factory()->create();
    $other = Organization::factory()->create();

    $active = Store::factory()->create(['organization_id' => $mine->getKey(), 'status' => StoreStatus::Active]);
    Store::factory()->create(['organization_id' => $mine->getKey(), 'status' => StoreStatus::Suspended]);
    Store::factory()->create(['organization_id' => $other->getKey(), 'status' => StoreStatus::Active]);

    $stores = app(StoreQueryContract::class)->liveStoresForOrganization($mine->getKey());

    // Only live, only theirs — the precondition "no store, no offer" (§3.4)
    // rests entirely on this answer, and the name is what a store picker
    // renders instead of a uuid.
    expect($stores)->toBe([$active->uuid => $active->name]);
});

it('gives an organization with no live store an empty list, not everyone’s', function (): void {
    $withStore = Organization::factory()->create();
    $without = Organization::factory()->create();

    Store::factory()->create(['organization_id' => $withStore->getKey(), 'status' => StoreStatus::Active]);

    // "No store, no offer" must be answerable as an empty list, never as a
    // fall-through that hands back somebody else's storefront.
    expect(app(StoreQueryContract::class)->liveStoresForOrganization($without->getKey()))->toBe([]);
});
