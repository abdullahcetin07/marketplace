<?php

declare(strict_types=1);

namespace App\Modules\Store\Application\Listeners;

use App\Modules\Organization\Domain\Events\StoreOpeningApproved;
use App\Modules\Store\Application\Actions\CreateStoreAction;
use App\Modules\Store\Domain\Contracts\StoreRepositoryContract;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Events\Dispatcher;

/**
 * THE MODULE BOUNDARY (ADR-028/032/033). Organization announces that an admin
 * approved a Store Opening Request; Store subscribes and creates the storefront.
 * Organization does not know Store exists — it fires `StoreOpeningApproved` into
 * whoever is listening. This class may import Organization's Domain\Events (the
 * consumer direction) and nothing else of Organization's (LayeringTest).
 *
 * IDEMPOTENT (ADR-032). Event delivery is at-least-once, so the same approval
 * may arrive more than once:
 *   1. A pre-check on the request UUID short-circuits a redelivery after commit.
 *   2. The UNIQUE `opening_request_uuid` is the backstop for a concurrent
 *      double-delivery — the loser catches the violation and does nothing.
 * Either way exactly one store, and `StoreCreated` fires exactly once (from the
 * single winning creation).
 *
 * NOT QUEUED. Creation is a real transaction that must complete for the approval
 * to mean anything; running it inline keeps the store observable the moment the
 * request is approved. A failure leaves the request approved-but-storeless — a
 * visible, retryable state, not a silent loss.
 *
 * @see docs/modules/Store.md §4
 */
final class CreateStoreFromApprovedRequest
{
    public function __construct(
        private readonly StoreRepositoryContract $stores,
        private readonly CreateStoreAction $createStore,
    ) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            StoreOpeningApproved::class => 'onStoreOpeningApproved',
        ];
    }

    public function onStoreOpeningApproved(StoreOpeningApproved $event): void
    {
        // Redelivery after the store already exists — nothing to do.
        if ($this->stores->findByOpeningRequestUuid($event->requestUuid) !== null) {
            return;
        }

        try {
            $this->createStore->run($event);
        } catch (UniqueConstraintViolationException) {
            // Lost a concurrent race: another delivery created the store (and
            // dispatched StoreCreated) between the pre-check and the insert.
            // That is success, not an error — idempotency holds.
        }
    }
}
