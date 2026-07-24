<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Listeners;

use App\Modules\Organization\Domain\Contracts\StoreOpeningRequestRepositoryContract;
use App\Modules\Store\Domain\Events\StoreCreated;
use Illuminate\Events\Dispatcher;

/**
 * Records the Store the request produced (ADR-032 back-reference).
 *
 * THE CONSUMER DIRECTION. Store owns the authoritative link
 * (`stores.opening_request_uuid`); this fills the request's convenience mirror
 * `created_store_uuid` so an admin looking at the request can see the store it
 * became. Organization subscribes to Store's `StoreCreated` and imports its
 * Domain\Events only — never a Store model or service (ADR-033, LayeringTest).
 *
 * This is the one Store-driven touch of the otherwise-frozen Organization
 * module — a "change a later module explicitly requires", not a new feature.
 * The write is a legitimate model update, so it lands in the request's audit
 * trail as the forensic record of the store's creation.
 *
 * @see docs/modules/Store.md §4.2
 */
final class RecordCreatedStore
{
    public function __construct(
        private readonly StoreOpeningRequestRepositoryContract $requests,
    ) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            StoreCreated::class => 'onStoreCreated',
        ];
    }

    public function onStoreCreated(StoreCreated $event): void
    {
        $request = $this->requests->findByUuid($event->openingRequestUuid);

        // The request may be absent in tests that dispatch StoreCreated directly;
        // in production the request always precedes the store it created.
        if ($request === null || $request->created_store_uuid !== null) {
            return;
        }

        $request->forceFill(['created_store_uuid' => $event->storeUuid])->save();
    }
}
