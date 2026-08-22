<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Domain\Models\OrderLine;
use App\Modules\Reviews\Domain\Models\Review;
use App\Modules\Reviews\Domain\Models\ReviewRequest;
use App\Modules\Reviews\Infrastructure\Notifications\ReviewRequestedNotification;
use App\Modules\Shipping\Domain\Models\Shipment;
use App\Shared\Enums\NotificationType;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Post-delivery review invitation (ADR-087)
|--------------------------------------------------------------------------
|
| A nightly sweep, not a delayed job: the whole review funnel would otherwise sit
| inside a queue for three days, where a restarted worker loses it silently.
|
| The rule tested hardest is idempotency, because the failure is loud in the worst
| way. Order re-offers every delivered line every night BY DESIGN — a reader that
| filtered on `review_requests` would be Order reaching into Reviews — so this
| module filters, and a UNIQUE index sits underneath in case two runs overlap. A
| buyer emailed once about a purchase is being served; a buyer emailed nightly
| unsubscribes, and takes the marketing list with them.
|
*/

beforeEach(function (): void {
    $this->seedAll();

    Notification::fake();
});

/**
 * A delivered line, `$daysAgo` days old.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @return array{order: Order, line: OrderLine}
 */
function invitableDelivery(Customer $customer, int $daysAgo = 5, string $title = 'Pamuklu Tişört'): array
{
    /** @var Order $order */
    $order = Order::factory()->create([
        'customer_id' => $customer->getKey(),
        'customer_uuid' => $customer->uuid,
        'status' => OrderStatus::Delivered,
        'placed_at' => now()->subDays($daysAgo + 2),
    ]);

    // Delivery is SHIPPING's fact and lives on the shipment (ADR-064); the order
    // carries only the status. The sweep joins the two through the Core contract.
    Shipment::factory()->create([
        'order_uuid' => $order->uuid,
        'seller_org_uuid' => $order->selling_org_uuid,
        'delivered_at' => now()->subDays($daysAgo),
    ]);

    $line = OrderLine::factory()->for($order)->create(['product_title' => $title]);

    return ['order' => $order, 'line' => $line];
}

it('invites a buyer once, and never again for the same purchase', function (): void {
    /** @var Customer $customer */
    $customer = Customer::factory()->create();

    // TWO LINES MINIMUM: Laravel arms the lazy-loading guard only when a query
    // hydrates more than one row, so a single-row fixture proves nothing about
    // the batched reads this sweep depends on.
    invitableDelivery($customer, title: 'Pamuklu Tişört');
    invitableDelivery($customer, title: 'Yün Atkı');

    $first = Artisan::call('reviews:request-pending');

    expect($first)->toBe(0)
        ->and(ReviewRequest::query()->count())->toBe(2);

    Notification::assertSentToTimes($customer, ReviewRequestedNotification::class, 2);

    // The second night. Order hands back the same lines; the table is what stops
    // a second email.
    Artisan::call('reviews:request-pending');

    expect(ReviewRequest::query()->count())->toBe(2);

    Notification::assertSentToTimes($customer, ReviewRequestedNotification::class, 2);
});

it('says nothing about a purchase already reviewed', function (): void {
    /** @var Customer $customer */
    $customer = Customer::factory()->create();

    $reviewed = invitableDelivery($customer, title: 'Değerlendirilmiş');
    invitableDelivery($customer, title: 'Değerlendirilmemiş');

    Review::factory()->create([
        'order_line_uuid' => $reviewed['line']->uuid,
        'customer_id' => $customer->getKey(),
        'customer_uuid' => $customer->uuid,
    ]);

    Artisan::call('reviews:request-pending');

    expect(ReviewRequest::query()->count())->toBe(1)
        ->and(ReviewRequest::query()->first()->order_line_uuid)
        ->not->toBe($reviewed['line']->uuid);

    Notification::assertSentToTimes($customer, ReviewRequestedNotification::class, 1);
});

it('waits out the delay before asking', function (): void {
    /** @var Customer $customer */
    $customer = Customer::factory()->create();

    // Delivered yesterday, against a three-day delay: asking now would ask about
    // a parcel rather than a product.
    invitableDelivery($customer, daysAgo: 1);
    invitableDelivery($customer, daysAgo: 2);

    Artisan::call('reviews:request-pending');

    expect(ReviewRequest::query()->count())->toBe(0);

    Notification::assertNothingSent();
});

it('honours the opt-out, and records it rather than re-asking nightly', function (): void {
    /** @var Customer $optedOut */
    $optedOut = Customer::factory()->create();
    /** @var Customer $willing */
    $willing = Customer::factory()->create();

    invitableDelivery($optedOut);
    invitableDelivery($willing);

    $optedOut->notificationPreferences()->create([
        'channel' => NotificationType::Mail,
        'notification_type' => ReviewRequestedNotification::class,
        'enabled' => false,
    ]);

    Artisan::call('reviews:request-pending');

    Notification::assertSentTo($willing, ReviewRequestedNotification::class);
    Notification::assertNotSentTo($optedOut, ReviewRequestedNotification::class);

    /*
    | THE SUPPRESSION IS WRITTEN DOWN. Skipping without a row would have the
    | sweep re-evaluate the same declining customer every night forever, and
    | would leave nobody able to say how much of the review funnel opt-out costs.
    */
    $suppressed = ReviewRequest::query()->where('customer_uuid', $optedOut->uuid)->first();

    expect($suppressed)->not->toBeNull()
        ->and($suppressed->sent_at)->toBeNull()
        ->and($suppressed->suppressed_reason)->toBe('opted_out');
});

it('sends nothing at all when the operator switches it off', function (): void {
    /** @var Customer $customer */
    $customer = Customer::factory()->create();

    invitableDelivery($customer);
    invitableDelivery($customer);

    /*
    | THE SETTING ROW HAS TO EXIST BEFORE IT CAN BE TURNED OFF. `settings()->set()`
    | UPDATES; it does not create. Without the seeder it is a silent no-op and this
    | test passes against the default `true` — proving nothing about the off switch.
    */
    $this->seed(Database\Modules\Settings\Seeders\SettingsSeeder::class);

    expect(settings()->set('reviews.request_enabled', false))->toBeTrue();

    Artisan::call('reviews:request-pending');

    // No rows either: an off switch that still marked purchases as handled would
    // silently burn them, and turning it back on would ask about nothing.
    expect(ReviewRequest::query()->count())->toBe(0);

    Notification::assertNothingSent();
});

it('leaves a cancelled or returned parcel alone', function (): void {
    /** @var Customer $customer */
    $customer = Customer::factory()->create();

    $returned = invitableDelivery($customer);
    Shipment::query()->where('order_uuid', $returned['order']->uuid)
        ->update(['returned_at' => now()->subDay()]);

    $cancelled = invitableDelivery($customer);
    $cancelled['order']->update(['status' => OrderStatus::Cancelled]);

    Artisan::call('reviews:request-pending');

    expect(ReviewRequest::query()->count())->toBe(0);

    Notification::assertNothingSent();
});

it('queues rather than sending inline, so a mail outage never 500s a sweep', function (): void {
    // `BaseNotification implements ShouldQueue`. With SES still sandboxed this is
    // what keeps an undeliverable invitation a failed job rather than an
    // exception in a scheduled command.
    expect(new ReviewRequestedNotification('Ürün', 'https://raftabul.com/hesap/siparislerim'))
        ->toBeInstanceOf(Illuminate\Contracts\Queue\ShouldQueue::class);
});
