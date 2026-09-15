<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Models\Customer;
use App\Modules\Marketing\Domain\Models\CheckoutSignal;
use App\Modules\Marketing\Presentation\Middleware\CaptureCheckoutSignals;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Exceptions;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| Marketing — browser signals captured on the pay request
|--------------------------------------------------------------------------
|
| The PayTR callback has no browser, so the pay request is where IP, user agent
| and (with consent) `_fbp`/`_fbc` are taken. What this file holds the capture
| to: the owner's basket only, Meta's cookie shapes only, never while disabled,
| and never at the cost of the payment request it rides on.
|
*/

const SIG_GROUP = '5e1f0c2a-7b3d-4c8e-9f10-2a3b4c5d6e7f';
const SIG_FBP = 'fb.1.1726400000000.1234567890';
const SIG_FBC = 'fb.1.1726400000000.IwAR2abc-DEF_ghi';

/**
 * @param array<string, mixed> $input
 */
function signalRequest(array $input = [], string $ip = '203.0.113.7', string $ua = 'Mozilla/5.0 Test'): Request
{
    $request = Request::create(
        '/api/v1/checkout/'.SIG_GROUP.'/pay',
        'POST',
        $input,
        server: ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $ua],
    );

    $route = (new Route('POST', 'api/v1/checkout/{group}/pay', fn () => null))->bind($request);
    $request->setRouteResolver(fn () => $route);

    return $request;
}

function signalOwner(?int $ownerId): void
{
    $mock = Mockery::mock(OrderQueryContract::class, function ($mock) use ($ownerId): void {
        $mock->shouldReceive('checkoutGroupCustomer')
            ->andReturn($ownerId === null ? null : ['id' => $ownerId, 'uuid' => 'c', 'email' => 'a@b.c']);
    });

    app()->instance(OrderQueryContract::class, $mock);
}

function runCapture(Request $request): Response
{
    return app(CaptureCheckoutSignals::class)->handle($request, fn () => response()->noContent());
}

beforeEach(function (): void {
    $this->seedPlatform();
    config(['marketing.meta.enabled' => true]);
});

it('sits on the pay route', function (): void {
    $route = app('router')->getRoutes()->getByName('api.v1.checkout.pay');

    expect($route)->not->toBeNull()
        ->and($route?->gatherMiddleware())->toContain(CaptureCheckoutSignals::class);
});

it("keeps the owner's IP, user agent and consented Meta cookies", function (): void {
    /** @var Customer $customer */
    $customer = Customer::factory()->create();
    $this->actingAs($customer, 'customer');
    signalOwner((int) $customer->getKey());

    $response = runCapture(signalRequest(['fbp' => SIG_FBP, 'fbc' => SIG_FBC]));

    $signal = CheckoutSignal::query()->where('checkout_group_uuid', SIG_GROUP)->sole();

    expect($response->getStatusCode())->toBe(204)
        ->and($signal->fbp)->toBe(SIG_FBP)
        ->and($signal->fbc)->toBe(SIG_FBC)
        ->and($signal->client_ip)->toBe('203.0.113.7')
        ->and($signal->client_user_agent)->toBe('Mozilla/5.0 Test');
});

it('drops anything that is not shaped like a Meta cookie', function (): void {
    /** @var Customer $customer */
    $customer = Customer::factory()->create();
    $this->actingAs($customer, 'customer');
    signalOwner((int) $customer->getKey());

    runCapture(signalRequest(['fbp' => '<script>', 'fbc' => ['fb.1.1.x']]));

    $signal = CheckoutSignal::query()->sole();

    expect($signal->fbp)->toBeNull()
        ->and($signal->fbc)->toBeNull()
        ->and($signal->client_ip)->toBe('203.0.113.7');
});

it("writes nothing for somebody else's basket", function (): void {
    /** @var Customer $customer */
    $customer = Customer::factory()->create();
    $this->actingAs($customer, 'customer');
    signalOwner((int) $customer->getKey() + 1000);

    $response = runCapture(signalRequest(['fbp' => SIG_FBP]));

    expect(CheckoutSignal::query()->count())->toBe(0)
        ->and($response->getStatusCode())->toBe(204);
});

it('lets a retried pay overwrite, so withdrawn consent clears the cookies', function (): void {
    /** @var Customer $customer */
    $customer = Customer::factory()->create();
    $this->actingAs($customer, 'customer');
    signalOwner((int) $customer->getKey());

    runCapture(signalRequest(['fbp' => SIG_FBP, 'fbc' => SIG_FBC]));
    runCapture(signalRequest([], '198.51.100.9'));

    $signal = CheckoutSignal::query()->sole();

    expect($signal->fbp)->toBeNull()
        ->and($signal->fbc)->toBeNull()
        ->and($signal->client_ip)->toBe('198.51.100.9');
});

it('captures nothing, and asks nothing, while disabled', function (): void {
    config(['marketing.meta.enabled' => false]);

    $mock = Mockery::mock(OrderQueryContract::class, function ($mock): void {
        $mock->shouldNotReceive('checkoutGroupCustomer');
    });
    app()->instance(OrderQueryContract::class, $mock);

    runCapture(signalRequest(['fbp' => SIG_FBP]));

    expect(CheckoutSignal::query()->count())->toBe(0);
});

it('never stands between the shopper and the payment', function (): void {
    Exceptions::fake();

    /** @var Customer $customer */
    $customer = Customer::factory()->create();
    $this->actingAs($customer, 'customer');

    $mock = Mockery::mock(OrderQueryContract::class, function ($mock): void {
        $mock->shouldReceive('checkoutGroupCustomer')->andThrow(new RuntimeException('orders unreachable'));
    });
    app()->instance(OrderQueryContract::class, $mock);

    $response = runCapture(signalRequest());

    expect($response->getStatusCode())->toBe(204);
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'orders unreachable');
});

it('prunes signals past the retention window', function (): void {
    config(['marketing.meta.signal_retention_days' => 7]);

    $old = CheckoutSignal::query()->create(['checkout_group_uuid' => SIG_GROUP, 'client_ip' => '203.0.113.7']);
    $old->forceFill(['updated_at' => now()->subDays(8)])->save();

    CheckoutSignal::query()->create([
        'checkout_group_uuid' => '6f2a1d3b-8c4e-4d9f-a021-3b4c5d6e7f80',
        'client_ip' => '203.0.113.8',
    ]);

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => $event->description === 'prune-marketing-checkout-signals');

    expect($event)->not->toBeNull();

    $event->run(app());

    expect(CheckoutSignal::query()->pluck('client_ip')->all())->toBe(['203.0.113.8']);
});
