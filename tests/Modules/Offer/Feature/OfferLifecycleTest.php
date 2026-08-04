<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Application\Actions\PauseOfferAction;
use App\Modules\Offer\Application\Actions\ReinstateOfferAction;
use App\Modules\Offer\Application\Actions\ResumeOfferAction;
use App\Modules\Offer\Application\Actions\SuspendOfferAction;
use App\Modules\Offer\Application\Actions\UpdateOfferPriceAction;
use App\Modules\Offer\Application\Actions\UpdateOfferStockAction;
use App\Modules\Offer\Application\Actions\WithdrawOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Offer\Domain\DTOs\UpdateOfferPriceDTO;
use App\Modules\Offer\Domain\DTOs\UpdateOfferStockDTO;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Events\OfferCreated;
use App\Modules\Offer\Domain\Events\OfferPriceChanged;
use App\Modules\Offer\Domain\Events\OfferStockChanged;
use App\Modules\Offer\Domain\Exceptions\OfferException;
use App\Modules\Offer\Domain\Models\Offer;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| The offer lifecycle — create, re-price, restock, pause, withdraw, suspend
|--------------------------------------------------------------------------
|
| Offers go live on save (ADR-044): there is no draft and no moderator. What
| stands in for moderation is VALIDATION, and each of the four preconditions
| (§3.4) is a different way for the marketplace to end up selling something it
| should not — so each gets its own refusal here.
|
| The other half is the suspension pair. Offers ship unmoderated, so reactive
| suspension is the ONLY oversight lever, and reinstating must undo the admin's
| action without overriding the seller's.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

/**
 * A published catalog variant, a company, and its live store — everything the
 * four preconditions ask about, in one fixture.
 *
 * @return array{org: Organization, store: Store, variant: ProductVariant, product: Product}
 */
function sellableFixture(ProductStatus $status = ProductStatus::Published): array
{
    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')->create(['status' => $status]);
    $variant = ProductVariant::factory()->for($product)->create();

    return ['org' => $organization, 'store' => $store, 'variant' => $variant, 'product' => $product];
}

/**
 * @param array{org: Organization, store: Store, variant: ProductVariant, product: Product} $fixture
 */
function createOfferDto(array $fixture, int $price = 12_990, ?int $listPrice = null, int $stock = 5): CreateOfferDTO
{
    return new CreateOfferDTO(
        variantUuid: $fixture['variant']->uuid,
        sellingOrgId: $fixture['org']->getKey(),
        sellingOrgUuid: $fixture['org']->uuid,
        storeUuid: $fixture['store']->uuid,
        priceMinor: $price,
        stockQuantity: $stock,
        listPriceMinor: $listPrice,
    );
}

/*
|--------------------------------------------------------------------------
| Creating — live immediately, but only if all four preconditions hold
|--------------------------------------------------------------------------
*/

it('lists a published variant and goes live immediately', function (): void {
    Event::fake([OfferCreated::class]);
    $fixture = sellableFixture();

    $offer = app(CreateOfferAction::class)->run(createOfferDto($fixture, 12_990, 19_990));

    // No draft, no review queue (ADR-044).
    expect($offer->status)->toBe(OfferStatus::Active)
        ->and($offer->price_minor)->toBe(12_990)
        ->and($offer->list_price_minor)->toBe(19_990)
        // Denormalized from the variant, never accepted from the caller.
        ->and($offer->product_uuid)->toBe($fixture['product']->uuid)
        ->and($offer->currency_id)->not->toBeNull();

    Event::assertDispatched(OfferCreated::class);
});

it('refuses to price a product that is not published', function (): void {
    foreach ([ProductStatus::Draft, ProductStatus::PendingReview, ProductStatus::Rejected, ProductStatus::Archived] as $status) {
        $fixture = sellableFixture($status);

        // The side door the catalog's moderation lifecycle exists to close: an
        // offer would make an unapproved product sellable.
        expect(fn () => app(CreateOfferAction::class)->run(createOfferDto($fixture)))
            ->toThrow(OfferException::class);
    }
});

it('refuses a variant that does not exist', function (): void {
    $fixture = sellableFixture();

    $dto = new CreateOfferDTO(
        variantUuid: 'no-such-variant',
        sellingOrgId: $fixture['org']->getKey(),
        sellingOrgUuid: $fixture['org']->uuid,
        storeUuid: $fixture['store']->uuid,
        priceMinor: 1_000,
        stockQuantity: 1,
    );

    expect(fn () => app(CreateOfferAction::class)->run($dto))->toThrow(OfferException::class);
});

it('refuses a company with no active store — no store, no offer', function (): void {
    $fixture = sellableFixture();
    $fixture['store']->forceFill(['status' => StoreStatus::Suspended])->save();

    expect(fn () => app(CreateOfferAction::class)->run(createOfferDto($fixture)))
        ->toThrow(OfferException::class);
});

it('refuses to list under another company’s store', function (): void {
    $mine = sellableFixture();
    $theirs = sellableFixture();

    $dto = new CreateOfferDTO(
        variantUuid: $mine['variant']->uuid,
        sellingOrgId: $mine['org']->getKey(),
        sellingOrgUuid: $mine['org']->uuid,
        // A live store — but not this company's.
        storeUuid: $theirs['store']->uuid,
        priceMinor: 1_000,
        stockQuantity: 1,
    );

    expect(fn () => app(CreateOfferAction::class)->run($dto))->toThrow(OfferException::class);
});

it('refuses a price of zero and a list price below the price', function (): void {
    $fixture = sellableFixture();

    expect(fn () => app(CreateOfferAction::class)->run(createOfferDto($fixture, 0)))
        ->toThrow(OfferException::class);

    // A struck-through price below the real one advertises a markup as a
    // discount.
    expect(fn () => app(CreateOfferAction::class)->run(createOfferDto($fixture, 12_990, 9_990)))
        ->toThrow(OfferException::class);
});

it('refuses a second offer for the same variant with a readable error', function (): void {
    $fixture = sellableFixture();
    app(CreateOfferAction::class)->run(createOfferDto($fixture));

    // The database would refuse this too; the action gets there first so the
    // seller reads "you already sell this" rather than a constraint violation.
    expect(fn () => app(CreateOfferAction::class)->run(createOfferDto($fixture, 9_990)))
        ->toThrow(OfferException::class);
});

/*
|--------------------------------------------------------------------------
| Re-pricing and restocking
|--------------------------------------------------------------------------
*/

it('re-prices through the action, recording both sides', function (): void {
    Event::fake([OfferPriceChanged::class]);
    $offer = Offer::factory()->priced(12_990)->create();

    app(UpdateOfferPriceAction::class)->run($offer, new UpdateOfferPriceDTO(
        priceMinor: 9_990,
        reason: 'Kampanya',
    ));

    expect($offer->fresh()->price_minor)->toBe(9_990);

    Event::assertDispatched(OfferPriceChanged::class, fn (OfferPriceChanged $event): bool => $event->previousPriceMinor === 12_990 && $event->priceMinor === 9_990);
});

it('distinguishes clearing a list price from leaving it alone', function (): void {
    $offer = Offer::factory()->priced(12_990, 19_990)->create();

    // Not supplied: untouched.
    app(UpdateOfferPriceAction::class)->run($offer, new UpdateOfferPriceDTO(priceMinor: 11_990));
    expect($offer->fresh()->list_price_minor)->toBe(19_990);

    // Supplied as null: cleared — what happens when a campaign ends.
    app(UpdateOfferPriceAction::class)->run($offer->fresh(), new UpdateOfferPriceDTO(
        priceMinor: 11_990,
        listPriceMinor: null,
        present: ['list_price_minor'],
    ));
    expect($offer->fresh()->list_price_minor)->toBeNull();
});

it('sets stock to zero without touching the status', function (): void {
    Event::fake([OfferStockChanged::class]);
    $offer = Offer::factory()->create(['stock_quantity' => 5]);

    app(UpdateOfferStockAction::class)->run($offer, new UpdateOfferStockDTO(stockQuantity: 0));

    // Out-of-stock is derived (ADR-043) — the offer keeps its price and its
    // place and simply stops winning.
    expect($offer->fresh()->stock_quantity)->toBe(0)
        ->and($offer->fresh()->status)->toBe(OfferStatus::Active)
        ->and($offer->fresh()->isInStock())->toBeFalse();

    Event::assertDispatched(OfferStockChanged::class, fn (OfferStockChanged $event): bool => $event->becameOutOfStock());
});

/*
|--------------------------------------------------------------------------
| Pause, resume, withdraw
|--------------------------------------------------------------------------
*/

it('pauses and resumes, clearing the cascade flag on resume', function (): void {
    $offer = Offer::factory()->create();

    app(PauseOfferAction::class)->run($offer, 'Stok bekleniyor');
    expect($offer->fresh()->status)->toBe(OfferStatus::Paused);

    app(ResumeOfferAction::class)->run($offer->fresh());
    expect($offer->fresh()->status)->toBe(OfferStatus::Active)
        ->and($offer->fresh()->paused_by_cascade)->toBeFalse();
});

it('withdraws as a soft delete that frees the variant for a fresh listing', function (): void {
    $fixture = sellableFixture();
    $offer = app(CreateOfferAction::class)->run(createOfferDto($fixture));

    app(WithdrawOfferAction::class)->run($offer);

    // The row survives for a future order line, and says WHY it is gone.
    expect(Offer::withTrashed()->whereKey($offer->getKey())->first()->status)
        ->toBe(OfferStatus::Withdrawn)
        ->and(Offer::query()->whereKey($offer->getKey())->exists())->toBeFalse();

    // And the seller may list the variant again — withdrawal is terminal for
    // the offer, not a ban on selling the thing.
    $fresh = app(CreateOfferAction::class)->run(createOfferDto($fixture, 8_990));
    expect($fresh->status)->toBe(OfferStatus::Active);
});

/*
|--------------------------------------------------------------------------
| Admin oversight — the only lever, and it must be undoable
|--------------------------------------------------------------------------
*/

it('suspends an offer, recording the actor, the reason and the prior state', function (): void {
    $admin = Admin::factory()->create();
    $offer = Offer::factory()->create();

    app(SuspendOfferAction::class)->run($offer, $admin, 'Aldatıcı fiyat');

    expect($offer->fresh()->status)->toBe(OfferStatus::Suspended)
        ->and($offer->fresh()->status_before_suspension)->toBe(OfferStatus::Active)
        ->and($offer->fresh()->suspended_by)->toBe($admin->getKey())
        ->and($offer->fresh()->suspension_reason)->toBe('Aldatıcı fiyat');
});

it('reinstates to the prior state, not to Active', function (): void {
    $admin = Admin::factory()->create();
    $offer = Offer::factory()->paused()->create();

    app(SuspendOfferAction::class)->run($offer, $admin, 'İnceleme');
    app(ReinstateOfferAction::class)->run($offer->fresh(), $admin);

    // Lifting a suspension undoes the ADMIN's action. Guessing Active would
    // re-list something the seller had deliberately taken down.
    expect($offer->fresh()->status)->toBe(OfferStatus::Paused)
        ->and($offer->fresh()->status_before_suspension)->toBeNull()
        ->and($offer->fresh()->suspended_by)->toBeNull();
});

it('never lets a seller edit or move a suspended offer', function (): void {
    $admin = Admin::factory()->create();
    $offer = Offer::factory()->create();
    app(SuspendOfferAction::class)->run($offer, $admin, 'İnceleme');

    $suspended = $offer->fresh();

    // Editing your way out of an oversight action — drop the abusive price and
    // quietly keep selling — is the obvious abuse, so every seller write
    // refuses, not just the status transitions.
    expect(fn () => app(UpdateOfferPriceAction::class)->run($suspended, new UpdateOfferPriceDTO(priceMinor: 1_000)))
        ->toThrow(OfferException::class);
    expect(fn () => app(UpdateOfferStockAction::class)->run($suspended, new UpdateOfferStockDTO(stockQuantity: 0)))
        ->toThrow(OfferException::class);
    expect(fn () => app(PauseOfferAction::class)->run($suspended))->toThrow(OfferException::class);
    expect(fn () => app(WithdrawOfferAction::class)->run($suspended))->toThrow(OfferException::class);
});

it('refuses to reinstate something that was never suspended', function (): void {
    $offer = Offer::factory()->paused()->create();

    // A no-op would silently overwrite the seller's own pause with Active.
    expect(fn () => app(ReinstateOfferAction::class)->run($offer))->toThrow(OfferException::class);
});
