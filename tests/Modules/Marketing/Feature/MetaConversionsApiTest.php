<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Modules\Marketing\Application\Listeners\SendMetaPurchase;
use App\Modules\Marketing\Domain\Contracts\ConversionsApiContract;
use App\Modules\Marketing\Domain\DTOs\PurchaseConversionDTO;
use App\Modules\Marketing\Infrastructure\MetaConversionsApiClient;
use App\Modules\Payment\Domain\Events\PaymentSucceeded;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Marketing — Meta Conversions API (server-side Purchase)
|--------------------------------------------------------------------------
|
| Three promises: a paid basket becomes exactly the Purchase the browser pixel
| would have sent (same event_id, so Meta dedups), nothing leaves while the
| integration is off, and no failure here can reach the payment callback.
|
| The listener is called directly rather than through `event()`: firing the real
| `PaymentSucceeded` would run Order, Payment and Shipping's listeners against a
| basket that does not exist. The wiring is asserted separately.
|
*/

const CAPI_PAYMENT = '9b2f1c7e-3a44-4d1e-9a0b-6f2d8e1c5a70';
const CAPI_GROUP = '1c0d7a52-8f3b-4e0a-b6c1-2a9e4d7f3b18';

function capiEvent(): PaymentSucceeded
{
    return new PaymentSucceeded(
        paymentUuid: CAPI_PAYMENT,
        checkoutGroupUuid: CAPI_GROUP,
        amountMinor: 129990,
        currencyCode: 'TRY',
        orderUuids: ['order-a', 'order-b'],
    );
}

/**
 * @return array<string, mixed>
 */
function capiLine(string $product, int $quantity, int $unitMinor): array
{
    return [
        'id' => 'line-'.$product,
        'variant_uuid' => 'variant-'.$product,
        'product_uuid' => $product,
        'title' => 'Ürün',
        'quantity' => $quantity,
        'unit_price_minor' => $unitMinor,
        'line_total_minor' => $quantity * $unitMinor,
        'tax_rate' => '20.00',
        'commission_minor' => null,
    ];
}

function capiOrders(): void
{
    $mock = Mockery::mock(OrderQueryContract::class, function ($mock): void {
        $mock->shouldReceive('checkoutGroupCustomer')->with(CAPI_GROUP)
            ->andReturn(['id' => 1, 'uuid' => 'customer-uuid', 'email' => 'Alici@Example.com ']);
        $mock->shouldReceive('orderLines')->with('order-a')
            ->andReturn([capiLine('product-1', 2, 49995)]);
        $mock->shouldReceive('orderLines')->with('order-b')
            ->andReturn([capiLine('product-2', 1, 30000), capiLine('product-1', 1, 49995)]);
    });

    app()->instance(OrderQueryContract::class, $mock);
}

/**
 * Captures what the job hands the sender instead of calling Meta.
 */
final class CapiSpySender implements ConversionsApiContract
{
    /** @var array<int, PurchaseConversionDTO> */
    public array $sent = [];

    public function sendPurchase(PurchaseConversionDTO $dto): void
    {
        $this->sent[] = $dto;
    }
}

function capiSpy(): CapiSpySender
{
    $spy = new CapiSpySender;

    app()->instance(ConversionsApiContract::class, $spy);

    return $spy;
}

beforeEach(function (): void {
    config([
        'marketing.meta.enabled' => true,
        'marketing.meta.pixel_id' => '2082722212251736',
        'marketing.meta.access_token' => 'secret-token',
        'marketing.meta.api_version' => 'v21.0',
        'marketing.meta.test_event_code' => '',
    ]);
});

it('listens to PaymentSucceeded by class-string', function (): void {
    Event::fake();

    Event::assertListening(
        'App\Modules\Payment\Domain\Events\PaymentSucceeded',
        [SendMetaPurchase::class, 'handle'],
    );
});

it('turns a paid basket into one Purchase keyed by the payment uuid', function (): void {
    capiOrders();
    $spy = capiSpy();

    app(SendMetaPurchase::class)->handle(capiEvent());

    expect($spy->sent)->toHaveCount(1);

    $dto = $spy->sent[0];

    expect($dto->eventId)->toBe(CAPI_PAYMENT)
        ->and($dto->valueMinor)->toBe(129990)
        ->and($dto->currencyCode)->toBe('TRY')
        ->and($dto->email)->toBe('Alici@Example.com ')
        // Product uuids, de-duplicated — the catalogue feed's g:id.
        ->and($dto->contentIds)->toBe(['product-1', 'product-2'])
        ->and($dto->contents)->toBe([
            ['id' => 'product-1', 'quantity' => 2, 'item_price' => '499.95'],
            ['id' => 'product-2', 'quantity' => 1, 'item_price' => '300.00'],
            ['id' => 'product-1', 'quantity' => 1, 'item_price' => '499.95'],
        ]);
});

it('does nothing, not even a query, while disabled', function (): void {
    config(['marketing.meta.enabled' => false]);

    $this->mock(OrderQueryContract::class, function ($mock): void {
        $mock->shouldNotReceive('checkoutGroupCustomer');
        $mock->shouldNotReceive('orderLines');
    });
    $spy = capiSpy();

    app(SendMetaPurchase::class)->handle(capiEvent());

    expect($spy->sent)->toBe([]);
});

it('never lets a failure escape into the payment callback', function (): void {
    Exceptions::fake();

    $this->mock(OrderQueryContract::class, function ($mock): void {
        $mock->shouldReceive('checkoutGroupCustomer')->andThrow(new RuntimeException('database gone'));
    });
    $spy = capiSpy();

    app(SendMetaPurchase::class)->handle(capiEvent());

    expect($spy->sent)->toBe([]);
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'database gone');
});

it('posts a hashed, decimal-string Purchase to the pixel, token in the body', function (): void {
    Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);
    config(['marketing.meta.test_event_code' => 'TEST123']);

    app(MetaConversionsApiClient::class)->sendPurchase(new PurchaseConversionDTO(
        eventId: CAPI_PAYMENT,
        valueMinor: 129990,
        currencyCode: 'TRY',
        email: ' Alici@Example.com',
        contentIds: ['product-1'],
        contents: [['id' => 'product-1', 'quantity' => 1, 'item_price' => '1299.90']],
        eventTime: 1_700_000_000,
    ));

    Http::assertSent(function (Request $request): bool {
        $event = $request['data'][0];

        return $request->url() === 'https://graph.facebook.com/v21.0/2082722212251736/events'
            && ! str_contains($request->url(), 'secret-token')
            && $request['access_token'] === 'secret-token'
            && $request['test_event_code'] === 'TEST123'
            && $event['event_name'] === 'Purchase'
            && $event['event_id'] === CAPI_PAYMENT
            && $event['action_source'] === 'website'
            && $event['user_data']['em'] === [hash('sha256', 'alici@example.com')]
            && $event['custom_data']['value'] === '1299.90'
            && $event['custom_data']['currency'] === 'TRY'
            && $event['custom_data']['content_ids'] === ['product-1'];
    });
});

it('sends nothing while disabled or without a token', function (bool $enabled, string $token): void {
    Http::fake();
    config(['marketing.meta.enabled' => $enabled, 'marketing.meta.access_token' => $token]);

    app(MetaConversionsApiClient::class)->sendPurchase(new PurchaseConversionDTO(
        eventId: CAPI_PAYMENT,
        valueMinor: 100,
        currencyCode: 'TRY',
        email: null,
        contentIds: [],
        contents: [],
        eventTime: 1_700_000_000,
    ));

    Http::assertNothingSent();
})->with([
    'disabled' => [false, 'secret-token'],
    'no token' => [true, ''],
]);

it('throws on a rejected event so the job retries, without echoing the token', function (): void {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'secret-token invalid']], 400)]);

    expect(fn () => app(MetaConversionsApiClient::class)->sendPurchase(new PurchaseConversionDTO(
        eventId: CAPI_PAYMENT,
        valueMinor: 100,
        currencyCode: 'TRY',
        email: null,
        contentIds: [],
        contents: [],
        eventTime: 1_700_000_000,
    )))->toThrow(RuntimeException::class, 'Meta Conversions API rejected the event (HTTP 400)');
});
