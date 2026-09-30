<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Models\Customer;
use App\Modules\Order\Domain\Models\Order;

/*
|--------------------------------------------------------------------------
| Who the payment page says is buying
|--------------------------------------------------------------------------
|
| Payment had no way to ask for a name, so it sent the buyer's EMAIL in PayTR's
| name field. A shopper who had just typed their name and address reached the
| payment page and was shown "Ad Soyad: ayse@gmail.com" over a dash for the
| address — which reads as a site that did not take their details properly, at
| the exact moment they are deciding whether to hand over a card.
|
| Measured the same day: 64 checkouts all reached the payment step, and 22 of
| them left it without paying.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

it('tells the gateway the customer’s name, not their e-mail', function (): void {
    /** @var Customer $customer */
    $customer = Customer::factory()->create([
        'first_name' => 'Ayşe',
        'last_name' => 'Yılmaz',
        'email' => 'ayse@example.com',
    ]);

    $order = Order::factory()->create([
        'customer_id' => $customer->getKey(),
        'customer_uuid' => $customer->uuid,
    ]);

    $resolved = app(OrderQueryContract::class)->checkoutGroupCustomer($order->checkout_group_uuid);

    expect($resolved['name'])->toBe('Ayşe Yılmaz')
        // The e-mail still travels, in the field that is actually for it.
        ->and($resolved['email'])->toBe('ayse@example.com');
});

it('collapses cleanly for a customer with one name', function (): void {
    /** @var Customer $customer */
    $customer = Customer::factory()->create([
        'first_name' => 'Ayşe',
        'last_name' => null,
        'email' => 'ayse@example.com',
    ]);

    $order = Order::factory()->create([
        'customer_id' => $customer->getKey(),
        'customer_uuid' => $customer->uuid,
    ]);

    // ADR-012: `last_name` is nullable for sole traders and single-name
    // cultures, and the trailing space must not reach a payment page.
    expect(app(OrderQueryContract::class)->checkoutGroupCustomer($order->checkout_group_uuid)['name'])
        ->toBe('Ayşe');
});

it('answers with an empty name rather than refusing when the account is gone', function (): void {
    $order = Order::factory()->create([
        'customer_id' => 999_999,
        'customer_uuid' => (string) Illuminate\Support\Str::uuid(),
    ]);

    $resolved = app(OrderQueryContract::class)->checkoutGroupCustomer($order->checkout_group_uuid);

    // A charge is not worth refusing over a missing label; the caller falls
    // back to the e-mail, which PayTR requires the field to carry.
    expect($resolved['name'])->toBe('')
        ->and($resolved['email'])->toBe('');
});

it('hands the gateway the delivery phone, normalised', function (): void {
    /** @var Customer $customer */
    $customer = Customer::factory()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->getKey(),
        'customer_uuid' => $customer->uuid,
        'shipping_address' => ['recipient_name' => 'Ayşe Yılmaz', 'phone' => '0532 123 45 67'],
    ]);

    /*
     * THE DELIVERY NUMBER, NOT THE ACCOUNT'S: it is mandatory, it is validated
     * (TurkishPhone), and it is already on its way to a courier — so a PSP
     * holding it is not a new exposure. Normalised, because "0532 123 45 67"
     * and "5321234567" are one number and a risk engine should see one.
     */
    expect(app(OrderQueryContract::class)->checkoutGroupCustomer($order->checkout_group_uuid)['phone'])
        ->toBe('5321234567');
});

it('sends no phone at all rather than one nobody can ring', function (): void {
    /** @var Customer $customer */
    $customer = Customer::factory()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->getKey(),
        'customer_uuid' => $customer->uuid,
        // A legacy row: nine digits, accepted before the rule existed.
        'shipping_address' => ['recipient_name' => 'Berf Yavuz', 'phone' => '534-320-588'],
    ]);

    expect(app(OrderQueryContract::class)->checkoutGroupCustomer($order->checkout_group_uuid)['phone'])
        ->toBe('');
});

it('never sends the delivery address', function (): void {
    /*
     * THE HALF THAT DID NOT CHANGE. A home address in a third party's logs buys
     * far less than the phone does, so it is still withheld — and this pins that
     * the contract does not quietly start carrying one.
     */
    $customer = Customer::factory()->create();
    $order = Order::factory()->create([
        'customer_id' => $customer->getKey(),
        'customer_uuid' => $customer->uuid,
        'shipping_address' => ['line1' => 'Bağdat Caddesi 120', 'phone' => '5321234567'],
    ]);

    $resolved = app(OrderQueryContract::class)->checkoutGroupCustomer($order->checkout_group_uuid);

    expect(array_keys((array) $resolved))->toBe(['id', 'uuid', 'name', 'phone', 'email'])
        ->and((string) json_encode($resolved))->not->toContain('Bağdat');
});
