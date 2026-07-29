<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Modules\Catalog\Application\Actions\ArchiveProductAction;
use App\Modules\Catalog\Domain\Events\ProductPublished;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Models\Offer;

/*
|--------------------------------------------------------------------------
| The product-lifecycle cascade (§3.5)
|--------------------------------------------------------------------------
|
| Two things are pinned, and the second is the one that is easy to get wrong.
|
|  1. Archiving a product stops every offer on it. Paused, never withdrawn:
|     archiving is often reversible and destroying sellers' prices to solve a
|     problem that may last a day is not a trade worth making.
|  2. Re-publishing reactivates EXACTLY the offers the archive paused, and
|     leaves alone the ones a seller paused for their own reasons. That
|     distinction is unrecoverable without `paused_by_cascade`.
|
| These fire the REAL Catalog events rather than calling Offer's cascade
| directly. That is deliberate: Offer subscribes by class-string (it may not
| import Catalog), so a rename in Catalog would break the wiring silently at
| runtime. This test is the thing that would catch it.
|
| Archiving goes through Catalog's action; re-publication dispatches the event
| directly, because PublishProductAction re-validates a product against its
| category's attribute schema and that guard is Catalog's business to test, not
| a precondition this file should have to satisfy to observe a cascade.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A published product carrying offers from two different sellers.
 *
 * @return array{product: Product, first: Offer, second: Offer}
 */
function productWithOffers(): array
{
    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')->published()->create();

    // The variant uuids are derived from the product so two fixtures in one
    // test do not collide on the one-offer-per-(org, variant) index.
    return [
        'product' => $product,
        'first' => Offer::factory()->forOrganization(1, 'org-a')
            ->forVariant($product->uuid.'-v1', $product->uuid)->create(),
        'second' => Offer::factory()->forOrganization(2, 'org-b')
            ->forVariant($product->uuid.'-v2', $product->uuid)->create(),
    ];
}

/**
 * Bring a product back the way Catalog announces it.
 */
function republish(Product $product): void
{
    ProductPublished::dispatch(
        $product->getKey(),
        $product->uuid,
        $product->category_id,
        $product->proposed_by_org_uuid,
        Admin::factory()->create()->getKey(),
    );
}

it('pauses every offer when the product is archived', function (): void {
    $fixture = productWithOffers();

    app(ArchiveProductAction::class)->run($fixture['product']);

    foreach (['first', 'second'] as $key) {
        $offer = $fixture[$key]->fresh();

        // Paused, not withdrawn — the seller's price survives the round trip.
        expect($offer->status)->toBe(OfferStatus::Paused)
            ->and($offer->paused_by_cascade)->toBeTrue();
    }
});

it('leaves an already-paused offer alone rather than failing the cascade', function (): void {
    $fixture = productWithOffers();
    $fixture['second']->forceFill(['status' => OfferStatus::Paused])->save();

    app(ArchiveProductAction::class)->run($fixture['product']);

    // One offer was active and is now cascade-paused; the other was already
    // paused by its seller and keeps that flag, so the re-publish below will
    // not touch it.
    expect($fixture['first']->fresh()->paused_by_cascade)->toBeTrue()
        ->and($fixture['second']->fresh()->paused_by_cascade)->toBeFalse();
});

it('reactivates only what the cascade paused when the product returns', function (): void {
    $fixture = productWithOffers();

    // One seller pauses their own offer BEFORE the product is pulled.
    $fixture['second']->forceFill(['status' => OfferStatus::Paused])->save();

    app(ArchiveProductAction::class)->run($fixture['product']);
    republish($fixture['product']->fresh());

    expect($fixture['first']->fresh()->status)->toBe(OfferStatus::Active)
        // The seller's own decision is not overridden by the platform's.
        ->and($fixture['second']->fresh()->status)->toBe(OfferStatus::Paused);
});

it('does nothing on a product’s first publication', function (): void {
    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')->create();

    $offer = Offer::factory()->forVariant('v-1', $product->uuid)->paused()->create();

    republish($product);

    // A first publication has no cascade-paused offers. An offer paused by its
    // seller before the product was ever approved must not be switched on.
    expect($offer->fresh()->status)->toBe(OfferStatus::Paused);
});

it('leaves other products’ offers untouched', function (): void {
    $fixture = productWithOffers();
    $other = productWithOffers();

    app(ArchiveProductAction::class)->run($fixture['product']);

    expect($other['first']->fresh()->status)->toBe(OfferStatus::Active)
        ->and($other['second']->fresh()->status)->toBe(OfferStatus::Active);
});
