<?php

declare(strict_types=1);

use App\Modules\Offer\Domain\Models\Offer;
use App\Modules\Offer\Presentation\Support\BuyBoxStanding;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;

/*
|--------------------------------------------------------------------------
| "Am I winning, and what do I have to beat?" (§5, ADR-045)
|--------------------------------------------------------------------------
|
| The two numbers on the seller's list, both computed on read. What matters is
| that they come from the SAME ordering the buyer's product page uses — the Core
| contract — so a seller can never be told they are first while a shopper sees
| someone else featured. A second ordering computed here would drift, and the
| drift would be invisible until a seller complained.
|
| The null cases carry as much weight as the ranks: an offer that is not
| competing gets "—", not a large number, because "you are 7th" and "you are not
| in the running" call for different actions.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

function liveStoreUuid(): string
{
    return Store::factory()->create(['status' => StoreStatus::Active])->uuid;
}

/**
 * Three sellers competing for one variant, cheapest last so nothing passes by
 * accident of insertion order.
 *
 * @return array{cheap: Offer, mid: Offer, dear: Offer}
 */
function threeWayVariant(string $variantUuid = 'v-1'): array
{
    $store = liveStoreUuid();

    return [
        'dear' => Offer::factory()->priced(19_990)->forOrganization(1, 'org-a')
            ->forVariant($variantUuid, 'p-1')->forStore($store)->create(),
        'mid' => Offer::factory()->priced(15_990)->forOrganization(2, 'org-b')
            ->forVariant($variantUuid, 'p-1')->forStore($store)->create(),
        'cheap' => Offer::factory()->priced(12_990)->forOrganization(3, 'org-c')
            ->forVariant($variantUuid, 'p-1')->forStore($store)->create(),
    ];
}

it('ranks three competing offers by price', function (): void {
    $offers = threeWayVariant();
    $standing = app(BuyBoxStanding::class);

    expect($standing->rank($offers['cheap']))->toBe(1)
        ->and($standing->rank($offers['mid']))->toBe(2)
        ->and($standing->rank($offers['dear']))->toBe(3)
        ->and($standing->competitorCount('v-1'))->toBe(3);
});

it('reports the winning price, which is what the seller has to beat', function (): void {
    threeWayVariant();

    expect(app(BuyBoxStanding::class)->winningPriceMinor('v-1'))->toBe(12_990);
});

it('gives no rank to an offer that is not competing', function (): void {
    $store = liveStoreUuid();

    $soldOut = Offer::factory()->priced(1_000)->outOfStock()
        ->forOrganization(1, 'org-a')->forVariant('v-2', 'p-2')->forStore($store)->create();
    $paused = Offer::factory()->priced(2_000)->paused()
        ->forOrganization(2, 'org-b')->forVariant('v-2', 'p-2')->forStore($store)->create();
    $suspended = Offer::factory()->priced(3_000)->suspended()
        ->forOrganization(3, 'org-c')->forVariant('v-2', 'p-2')->forStore($store)->create();

    $standing = app(BuyBoxStanding::class);

    // The cheapest row in the table gets no rank at all — which is the point.
    // Telling a sold-out seller they are "1st" would send them to cut a price
    // when what they need is to restock.
    expect($standing->rank($soldOut))->toBeNull()
        ->and($standing->rank($paused))->toBeNull()
        ->and($standing->rank($suspended))->toBeNull();
});

it('gives no rank when the seller’s own store is dark', function (): void {
    $dark = Store::factory()->create(['status' => StoreStatus::Suspended])->uuid;

    $offer = Offer::factory()->priced(1_000)->forOrganization(1, 'org-a')
        ->forVariant('v-3', 'p-3')->forStore($dark)->create();

    // Eligible by its own columns, absent from the contract's answer. A real
    // state, not a bug: the offer is fine and the shop is closed.
    expect($offer->isBuyBoxEligible())->toBeTrue()
        ->and(app(BuyBoxStanding::class)->rank($offer))->toBeNull();
});

it('reports no winning price for a variant nobody is selling', function (): void {
    $store = liveStoreUuid();
    Offer::factory()->outOfStock()->forVariant('v-4', 'p-4')->forStore($store)->create();

    $standing = app(BuyBoxStanding::class);

    expect($standing->winningPriceMinor('v-4'))->toBeNull()
        ->and($standing->competitorCount('v-4'))->toBe(0);
});

it('breaks a price tie the same way the buyer’s page does', function (): void {
    $store = liveStoreUuid();

    $first = Offer::factory()->priced(9_990)->forOrganization(1, 'org-a')
        ->forVariant('v-5', 'p-5')->forStore($store)
        ->create(['created_at' => now()->subDays(3)]);
    $second = Offer::factory()->priced(9_990)->forOrganization(2, 'org-b')
        ->forVariant('v-5', 'p-5')->forStore($store)
        ->create(['created_at' => now()->subDay()]);

    $standing = app(BuyBoxStanding::class);

    // Same rule, same source — whoever listed at that price first keeps it.
    expect($standing->rank($first))->toBe(1)
        ->and($standing->rank($second))->toBe(2);
});

it('does not count another variant’s offers as competition', function (): void {
    $mine = threeWayVariant('v-6');
    threeWayVariant('v-7');

    $standing = app(BuyBoxStanding::class);

    expect($standing->competitorCount('v-6'))->toBe(3)
        ->and($standing->rank($mine['cheap']))->toBe(1);
});
