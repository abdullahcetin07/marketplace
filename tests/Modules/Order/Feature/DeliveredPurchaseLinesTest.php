<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Domain\Models\OrderLine;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The review gate, from Order's side (ADR-067)
|--------------------------------------------------------------------------
|
| Reviews may not read `orders`, so this method is the only thing standing
| between "a buyer says they bought it" and "the platform knows they did". Every
| test here is a way somebody could claim a purchase that is not theirs:
|
|   NOT DELIVERED    a paid, unshipped order has no experience to report
|   NOT YOURS        another customer's delivered line
|   NOT THIS PRODUCT a different line of the same order
|
| It returns LINES rather than a boolean because a review binds to one line, and
| the seller tag it will be stamped with comes from here.
|
*/

beforeEach(function (): void {
    $this->orders = app(OrderQueryContract::class);
    $this->product = (string) Str::uuid();
});

/**
 * A delivered order with one line for `$product`.
 *
 * Named for this file: Pest shares ONE global function namespace.
 */
function gateOrder(string $customerUuid, string $productUuid, OrderStatus $status = OrderStatus::Delivered): Order
{
    /** @var Order $order */
    $order = Order::factory()->create([
        'customer_uuid' => $customerUuid,
        'status' => $status,
        'placed_at' => now()->subDays(3),
    ]);

    OrderLine::factory()->for($order)->create([
        'product_uuid' => $productUuid,
        'product_title' => 'Pamuklu Tişört',
        'variant_label' => 'Mavi / M',
    ]);

    return $order;
}

it('hands back a delivered line with the seller it was bought from', function (): void {
    $order = gateOrder('musteri', $this->product);

    $lines = $this->orders->deliveredPurchaseLines('musteri', $this->product);

    expect($lines)->toHaveCount(1);

    /*
     * **THE SELLER TAG COMES FROM HERE AND NOWHERE ELSE** (ADR-066). It is what
     * `CreateReviewAction` stamps on the review, so a buyer cannot attribute
     * their opinion to a shop they did not buy from — there is no field on the
     * submit DTO that could carry one.
     */
    expect($lines[0]['store_uuid'])->toBe($order->store_uuid)
        ->and($lines[0]['selling_org_uuid'])->toBe($order->selling_org_uuid)
        // The title AS BOUGHT, so the "Değerlendir" screen names what actually
        // arrived rather than whatever the catalogue says today (ADR-053).
        ->and($lines[0]['product_title'])->toBe('Pamuklu Tişört')
        ->and($lines[0]['variant_label'])->toBe('Mavi / M')
        ->and($lines[0]['purchased_at'])->not->toBeNull();
});

it('refuses a paid order that has not arrived', function (): void {
    gateOrder('musteri', $this->product, OrderStatus::Paid);

    /*
     * THE GATE IS DELIVERY, NOT PAYMENT (ADR-067). "Kullandım, şöyleymiş" is the
     * honesty a review promises; a parcel still with a carrier has nothing to
     * report, however completely it has been paid for.
     */
    expect($this->orders->deliveredPurchaseLines('musteri', $this->product))->toBe([]);
});

it('refuses another customer’s delivered line', function (): void {
    gateOrder('baskasi', $this->product);

    expect($this->orders->deliveredPurchaseLines('musteri', $this->product))->toBe([]);
});

it('returns only the lines for the product asked about', function (): void {
    $order = gateOrder('musteri', $this->product);

    // The same delivered order, a different product on it.
    OrderLine::factory()->for($order)->create(['product_uuid' => (string) Str::uuid()]);

    $lines = $this->orders->deliveredPurchaseLines('musteri', $this->product);

    /*
     * ONE LINE, NOT TWO — and the filter is in the eager load rather than after
     * it, so an order of thirty items fetches the one that matters.
     */
    expect($lines)->toHaveCount(1);
});

it('gives a repeat buyer one line per purchase', function (): void {
    gateOrder('musteri', $this->product);
    gateOrder('musteri', $this->product);

    /*
     * **TWO PURCHASES, TWO REVIEWABLE LINES** (ADR-067, owner decision). Each is
     * a distinct experience — a second one may be a replacement, a gift, or the
     * same thing a year later — which is why the review's uniqueness sits on the
     * LINE and this method does not deduplicate by product.
     */
    expect($this->orders->deliveredPurchaseLines('musteri', $this->product))->toHaveCount(2);
});

it('answers empty for a customer who has bought nothing', function (): void {
    expect($this->orders->deliveredPurchaseLines('hic-alisveris-yapmamis', $this->product))->toBe([]);
});
