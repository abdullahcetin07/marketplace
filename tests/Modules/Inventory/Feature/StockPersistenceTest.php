<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\InventoryQueryContract;
use App\Modules\Inventory\Domain\Contracts\StockItemRepositoryContract;
use App\Modules\Inventory\Domain\Enums\StockMovementType;
use App\Modules\Inventory\Domain\Models\StockItem;
use App\Modules\Inventory\Domain\Models\StockMovement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Stock persistence — the guarantees the database itself makes
|--------------------------------------------------------------------------
|
| Three of them, and each is a different way for a marketplace to oversell:
|
|  1. ONE POOL per (org, variant) (ADR-051). Two pools for one seller-variant
|     would each hold half the truth and both would be wrong.
|  2. Movements are APPEND-ONLY (ADR-050, non-negotiable #9). The projection's
|     claim to be correct is that it can be recomputed from the ledger; an
|     editable ledger removes that claim.
|  3. Counts never go NEGATIVE — enforced by the column type, not by a code path
|     somebody could route around.
|
| Plus the read port, whose most important answer is the boring one: a variant
| nobody stocks is zero, not an exception.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

it('refuses a second pool for the same seller and variant', function (): void {
    StockItem::factory()->forOrganization(7, 'org-a')->forVariant('v-1')->create();

    // ADR-051 — one pool, enforced by the database rather than by a
    // check-then-insert, which races.
    expect(fn () => StockItem::factory()->forOrganization(7, 'org-a')->forVariant('v-1')->create())
        ->toThrow(QueryException::class);
});

it('lets a different seller stock the same variant', function (): void {
    StockItem::factory()->forOrganization(7, 'org-a')->forVariant('v-1')->create();
    $competitor = StockItem::factory()->forOrganization(8, 'org-b')->forVariant('v-1')->create();

    // One product, many sellers (ADR-042) — stock is per seller, or the
    // marketplace is a shop.
    expect($competitor->exists)->toBeTrue()
        ->and(StockItem::query()->forVariant('v-1')->count())->toBe(2);
});

it('refuses to update or delete a movement', function (): void {
    $movement = StockMovement::factory()->create();

    // Both hooks cancel the operation. There is no escape hatch, and there must
    // not be one.
    expect($movement->update(['note' => 'rewritten']))->toBeFalse()
        ->and($movement->delete())->toBeFalse();

    expect($movement->fresh()->note)->toBeNull()
        ->and(StockMovement::query()->whereKey($movement->getKey())->exists())->toBeTrue();
});

it('sums the ledger to the projection it stores', function (): void {
    $item = StockItem::factory()->stocked(0)->create();

    StockMovement::factory()->for($item, 'stockItem')
        ->ofType(StockMovementType::SellerAdjustment)->moving(10)->create();
    StockMovement::factory()->for($item, 'stockItem')
        ->ofType(StockMovementType::Reserved)->moving(0, 3)->create();
    StockMovement::factory()->for($item, 'stockItem')
        ->ofType(StockMovementType::Committed)->moving(-3, -3)->create();

    // The rebuild ADR-050 promises: on_hand and reserved are SUM(deltas), so a
    // projection that ever disagreed could be recomputed from here.
    $movements = StockMovement::query()->where('stock_item_id', $item->getKey())->get();

    expect($movements->sum('on_hand_delta'))->toBe(7)
        ->and($movements->sum('reserved_delta'))->toBe(0);
});

it('refuses a negative count at the storage layer', function (): void {
    $item = StockItem::factory()->stocked(1)->create();

    // Unsigned columns, so on Postgres this is impossible BELOW the application
    // rather than merely guarded above it.
    expect(fn () => $item->forceFill(['on_hand' => -1])->save())
        ->toThrow(QueryException::class);
})->skip(
    fn (): bool => DB::getDriverName() !== 'pgsql',
    'SQLite does not enforce UNSIGNED (nor the CHECK the migration adds on Postgres), '
    .'so under the suite the non-negative guarantee is the actions\' — asserted in '
    .'StockMovementTest rather than here.',
);

it('clamps a nonsensical projection when reading, whatever the driver stored', function (): void {
    // The belt under that brace, and the one that holds everywhere: even if a
    // row somehow carried reserved > on_hand, a storefront must never render a
    // negative "available".
    $item = (new StockItem)->forceFill(['on_hand' => 2, 'reserved' => 5]);

    expect($item->available())->toBe(0)
        ->and($item->isAvailable())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The read port
|--------------------------------------------------------------------------
*/

it('answers availability, on-hand and sellability through the contract', function (): void {
    StockItem::factory()->forOrganization(7, 'org-a')->forVariant('v-1')->stocked(10, 4)->create();

    $query = app(InventoryQueryContract::class);

    expect($query->onHandFor('v-1', 'org-a'))->toBe(10)
        ->and($query->availableFor('v-1', 'org-a'))->toBe(6)
        ->and($query->isAvailable('v-1', 'org-a'))->toBeTrue()
        ->and($query->isAvailable('v-1', 'org-a', 6))->toBeTrue()
        ->and($query->isAvailable('v-1', 'org-a', 7))->toBeFalse();
});

it('answers zero for a variant the seller never stocked', function (): void {
    $query = app(InventoryQueryContract::class);

    // An ordinary read, not an error: the buy box asks about variants nobody
    // sells all the time, and throwing would put an exception on the hottest
    // path the platform has.
    expect($query->availableFor('no-such-variant', 'org-a'))->toBe(0)
        ->and($query->onHandFor('no-such-variant', 'org-a'))->toBe(0)
        ->and($query->isAvailable('no-such-variant', 'org-a'))->toBeFalse();
});

it('keeps one seller’s stock out of another’s answer', function (): void {
    StockItem::factory()->forOrganization(7, 'org-a')->forVariant('v-1')->stocked(10)->create();

    expect(app(InventoryQueryContract::class)->availableFor('v-1', 'org-b'))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The repository's tenancy vocabulary
|--------------------------------------------------------------------------
*/

it('scopes a seller to their own organizations, and gives a member of nothing nothing', function (): void {
    $repository = app(StockItemRepositoryContract::class);

    $mine = StockItem::factory()->forOrganization(7, 'org-a')->forVariant('v-1')->create();
    $theirs = StockItem::factory()->forOrganization(8, 'org-b')->forVariant('v-2')->create();

    expect($repository->forOrganizations([7])->pluck('uuid')->all())->toBe([$mine->uuid])
        ->and($repository->forOrganizations([7])->pluck('uuid')->all())->not->toContain($theirs->uuid)
        // "No memberships" must mean "no rows", never "no filter".
        ->and($repository->forOrganizations([])->all())->toBe([]);
});

it('reads a pool’s history newest first, bounded', function (): void {
    $item = StockItem::factory()->create();

    foreach (range(1, 5) as $i) {
        StockMovement::factory()->for($item, 'stockItem')->moving($i)->create();
    }

    $movements = app(StockItemRepositoryContract::class)->movementsFor($item, 3);

    // The ledger grows without limit by design, so a history screen reads a
    // window of it — newest first, because that is what a seller is looking for.
    expect($movements)->toHaveCount(3)
        ->and($movements->first()->on_hand_delta)->toBe(5);
});

it('locks the pool it hands back for a write', function (): void {
    StockItem::factory()->forOrganization(7, 'org-a')->forVariant('v-1')->create();

    // The lock is a no-op on SQLite (the suite serialises writes anyway), so
    // what this pins is that the lookup still RESOLVES through the locking
    // path — a typo there would fail silently everywhere but Postgres.
    $locked = DB::transaction(
        fn () => app(StockItemRepositoryContract::class)->lockForUpdate('org-a', 'v-1'),
    );

    expect($locked)->not->toBeNull()
        ->and($locked->variant_uuid)->toBe('v-1');
});
