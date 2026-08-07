<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Domain\Models\OrderLine;
use App\Modules\Reviews\Domain\Models\Review;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The gate under attack (Reviews.md §10)
|--------------------------------------------------------------------------
|
| `ReviewGateTest` proves the action's logic against a FAKE Order port.
| `CustomerReviewApiTest` proves the happy path end to end. This file is neither:
| it is the adversarial pass, over HTTP, against the real port — every way a
| client could try to write a review it has not earned, or attribute one to a
| seller it did not buy from.
|
| **THE TWO PROPERTIES BEING DEFENDED:**
|
|   1. A review can only exist for a purchase THIS buyer was DELIVERED.
|   2. Its seller tag is the one on that purchase, whatever the request says.
|
| Property 2 is the one that would be silent if it broke: a wrong tag does not
| error, it just damages the wrong merchant.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

/**
 * A delivered purchase, plus a second shop the buyer never bought from.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @return array{product: Product, line: OrderLine, realStore: Store, otherStore: Store, order: Order}
 */
function gateSecurityFixture(Customer $customer): array
{
    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')->published()
        ->create(['title_tr' => 'Kulaklık', 'title_en' => 'Headphones']);

    $realStore = Store::factory()->create(['status' => StoreStatus::Active, 'name' => 'Gerçek Satıcı']);
    $otherStore = Store::factory()->create(['status' => StoreStatus::Active, 'name' => 'Masum Satıcı']);

    /** @var Order $order */
    $order = Order::factory()->create([
        'customer_id' => $customer->getKey(),
        'customer_uuid' => $customer->uuid,
        'store_uuid' => $realStore->uuid,
        'status' => OrderStatus::Delivered,
        'placed_at' => now()->subDay(),
    ]);

    $line = OrderLine::factory()->for($order)->create(['product_uuid' => $product->uuid]);

    return [
        'product' => $product,
        'line' => $line,
        'realStore' => $realStore,
        'otherStore' => $otherStore,
        'order' => $order,
    ];
}

it('cannot be talked into tagging a seller the buyer never bought from', function (): void {
    $customer = $this->actingAsCustomer();
    $fixture = gateSecurityFixture($customer);

    /*
     * **THE ATTACK THAT WOULD BE SILENT.** A wrong seller tag throws no error —
     * it just attaches somebody's complaint to a merchant who never touched the
     * order. So the request is stuffed with every field that could carry one, and
     * every one of them must be ignored: `SubmitReviewDTO` has no property to
     * receive them, and the action copies the tag from the verified LINE.
     */
    $this->postJson('/api/v1/reviews', [
        'product' => $fixture['product']->uuid,
        'order_line_uuid' => $fixture['line']->uuid,
        'rating' => 1,
        'body' => 'Berbat',
        'store_uuid' => $fixture['otherStore']->uuid,
        'seller_id' => $fixture['otherStore']->uuid,
        'selling_org_uuid' => (string) Str::uuid(),
        'variant_uuid' => (string) Str::uuid(),
    ])->assertCreated();

    $review = Review::query()->firstOrFail();

    expect($review->store_uuid)->toBe($fixture['realStore']->uuid)
        ->and($review->store_uuid)->not->toBe($fixture['otherStore']->uuid)
        ->and($review->selling_org_uuid)->toBe($fixture['order']->selling_org_uuid);
});

it('cannot be talked into publishing itself', function (): void {
    $customer = $this->actingAsCustomer();
    $fixture = gateSecurityFixture($customer);

    // The one field that would make moderation pointless if it were fillable.
    $this->postJson('/api/v1/reviews', [
        'product' => $fixture['product']->uuid,
        'order_line_uuid' => $fixture['line']->uuid,
        'rating' => 5,
        'status' => 'published',
        'moderated_by' => 1,
    ])->assertCreated();

    expect(Review::query()->firstOrFail()->status->isPublished())->toBeFalse();

    // And it is invisible where it counts.
    $this->getJson('/api/v1/products/'.$fixture['product']->uuid.'/reviews')
        ->assertOk()
        ->assertJsonPath('meta.summary.count', 0);
});

it('cannot be talked into signing somebody else’s name', function (): void {
    $customer = $this->actingAsCustomer(
        Customer::factory()->create(['first_name' => 'Ayşe', 'last_name' => 'Yılmaz']),
    );
    $fixture = gateSecurityFixture($customer);

    $this->postJson('/api/v1/reviews', [
        'product' => $fixture['product']->uuid,
        'order_line_uuid' => $fixture['line']->uuid,
        'rating' => 5,
        'author_name' => 'Mehmet K.',
        'customer_id' => 999,
        'customer_uuid' => (string) Str::uuid(),
    ])->assertCreated();

    $review = Review::query()->firstOrFail();

    // Computed from the ACTOR, never the payload.
    expect($review->author_name)->toBe('Ayşe Y.')
        ->and($review->customer_id)->toBe((int) $customer->getKey());
});

it('refuses a forged line uuid over HTTP, for a product the buyer really owns', function (): void {
    $customer = $this->actingAsCustomer();
    $fixture = gateSecurityFixture($customer);

    /*
     * EVERYTHING ABOUT THIS LOOKS RIGHT — a real customer, a real delivered
     * product — and only the line-by-line check catches it. It is why the port
     * answers with lines rather than "has this person bought this product".
     */
    $this->postJson('/api/v1/reviews', [
        'product' => $fixture['product']->uuid,
        'order_line_uuid' => (string) Str::uuid(),
        'rating' => 5,
    ])->assertStatus(422);

    expect(Review::query()->count())->toBe(0);
});

it('refuses a line belonging to a different product of the same order', function (): void {
    $customer = $this->actingAsCustomer();
    $fixture = gateSecurityFixture($customer);

    // A second, unrelated line on the SAME delivered order.
    $otherLine = OrderLine::factory()->for($fixture['order'])->create([
        'product_uuid' => (string) Str::uuid(),
    ]);

    /*
     * THE GATE IS KEYED ON (CUSTOMER, PRODUCT, LINE) — all three. Naming a line
     * the buyer genuinely owns, under a product it does not belong to, would
     * otherwise let one delivered order review the whole catalogue.
     */
    $this->postJson('/api/v1/reviews', [
        'product' => $fixture['product']->uuid,
        'order_line_uuid' => $otherLine->uuid,
        'rating' => 5,
    ])->assertStatus(422);

    expect(Review::query()->count())->toBe(0);
});

it('refuses a purchase that was cancelled rather than delivered', function (): void {
    $customer = $this->actingAsCustomer();
    $fixture = gateSecurityFixture($customer);

    $fixture['order']->forceFill(['status' => OrderStatus::Cancelled])->save();

    // The gate is DELIVERY (ADR-067) — not payment, and certainly not "an order
    // existed once".
    $this->postJson('/api/v1/reviews', [
        'product' => $fixture['product']->uuid,
        'order_line_uuid' => $fixture['line']->uuid,
        'rating' => 5,
    ])->assertStatus(422);

    expect(Review::query()->count())->toBe(0);
});

it('will not let a buyer delete a review by guessing a uuid', function (): void {
    $this->actingAsCustomer();

    $theirs = Review::factory()->forCustomer(999, 'baskasi')->published()->create();

    // 403 rather than 404: the row exists and the policy is what refuses. The
    // buyer learns nothing about it beyond that it is not theirs.
    $this->deleteJson('/api/v1/reviews/'.$theirs->uuid)->assertForbidden();

    expect(Review::query()->whereKey($theirs->getKey())->exists())->toBeTrue();
});
