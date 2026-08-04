<?php

declare(strict_types=1);

use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Localization\Domain\Models\Language;
use App\Modules\Organization\Domain\Events\StoreOpeningApproved;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\StoreOpeningRequest;
use App\Modules\Store\Application\Actions\CreateStoreAction;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Events\StoreCreated;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Store — creation from an approved request (ADR-028/032/033)
|--------------------------------------------------------------------------
|
| The one creation path: consuming StoreOpeningApproved. No seller action, no
| self-creation. Idempotent on the request UUID, and the storefront reports back.
*/

beforeEach(function (): void {
    // CreateStoreAction resolves the platform default language/currency/timezone.
    $this->seedPlatform();
});

/**
 * Build an approved request and the event Organization would fire for it.
 *
 * @return array{0: Organization, 1: StoreOpeningRequest, 2: StoreOpeningApproved}
 */
function approvedRequestEvent(?string $slug = null): array
{
    $org = Organization::factory()->approved()->create();
    $request = StoreOpeningRequest::factory()->for($org)->approved()->create(
        $slug !== null ? ['slug' => $slug] : [],
    );

    $event = new StoreOpeningApproved(
        organizationId: $org->getKey(),
        organizationUuid: $org->uuid,
        requestUuid: $request->uuid,
        requestedBy: $request->requested_by,
        storeName: $request->store_name,
        slug: $request->slug,
    );

    return [$org, $request, $event];
}

it('creates a Draft store when a store opening request is approved (ADR-028)', function (): void {
    [$org, $request, $event] = approvedRequestEvent(slug: 'acme-goods');

    StoreOpeningApproved::dispatch($event->organizationId, $event->organizationUuid, $event->requestUuid, $event->requestedBy, $event->storeName, $event->slug);

    $store = Store::query()->where('opening_request_uuid', $request->uuid)->first();

    expect($store)->not->toBeNull()
        ->and($store->status)->toBe(StoreStatus::Draft)
        ->and($store->organization_id)->toBe($org->getKey())
        ->and($store->organization_uuid)->toBe($org->uuid)
        ->and($store->name)->toBe($request->store_name)
        ->and($store->slug)->toBe('acme-goods')
        ->and($store->store_number)->not->toBeEmpty()
        // Born with the platform default locale (§4.3) — not inherited from the org.
        ->and((int) $store->default_language_id)->toBe((int) Language::query()->where('is_default', true)->value('id'))
        ->and((int) $store->default_currency_id)->toBe((int) Currency::query()->where('is_default', true)->value('id'));
});

it('is idempotent — replaying the approval creates exactly one store (ADR-032)', function (): void {
    [, $request, $event] = approvedRequestEvent();

    // At-least-once delivery: the same approval arrives three times.
    StoreOpeningApproved::dispatch($event->organizationId, $event->organizationUuid, $event->requestUuid, $event->requestedBy, $event->storeName, $event->slug);
    StoreOpeningApproved::dispatch($event->organizationId, $event->organizationUuid, $event->requestUuid, $event->requestedBy, $event->storeName, $event->slug);
    StoreOpeningApproved::dispatch($event->organizationId, $event->organizationUuid, $event->requestUuid, $event->requestedBy, $event->storeName, $event->slug);

    expect(Store::query()->where('opening_request_uuid', $request->uuid)->count())->toBe(1);
});

it('reports back so the request records the created store uuid (ADR-032 back-reference)', function (): void {
    [, $request, $event] = approvedRequestEvent();

    StoreOpeningApproved::dispatch($event->organizationId, $event->organizationUuid, $event->requestUuid, $event->requestedBy, $event->storeName, $event->slug);

    $store = Store::query()->where('opening_request_uuid', $request->uuid)->firstOrFail();

    expect($request->refresh()->created_store_uuid)->toBe($store->uuid);
});

it('fires StoreCreated carrying the org identifiers', function (): void {
    [$org, $request, $event] = approvedRequestEvent();

    Event::fake([StoreCreated::class]);

    CreateStoreAction::make()->run($event);

    Event::assertDispatched(StoreCreated::class, function (StoreCreated $e) use ($org, $request): bool {
        return $e->organizationId === $org->getKey()
            && $e->organizationUuid === $org->uuid
            && $e->openingRequestUuid === $request->uuid
            && $e->storeUuid !== '';
    });
});

it('suffixes a slug that is already taken — slugs are global handles', function (): void {
    Store::factory()->create(['slug' => 'taken']);

    [, $request, $event] = approvedRequestEvent(slug: 'taken');

    StoreOpeningApproved::dispatch($event->organizationId, $event->organizationUuid, $event->requestUuid, $event->requestedBy, $event->storeName, $event->slug);

    $store = Store::query()->where('opening_request_uuid', $request->uuid)->firstOrFail();

    expect($store->slug)->toBe('taken-2');
});
