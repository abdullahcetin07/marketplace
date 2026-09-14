<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Application\Listeners;

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Modules\Marketing\Application\Jobs\SendMetaConversionJob;
use App\Modules\Marketing\Domain\DTOs\PurchaseConversionDTO;

/**
 * A paid basket becomes one server-side Meta Purchase (Marketing.md).
 *
 * **SUBSCRIBES TO `PaymentSucceeded` BY CLASS-STRING** — the platform's standard
 * for a cross-module event (Payment.md §3). The provider wires the string; this
 * reads public properties off a plain object with `data_get`, so Marketing
 * imports no module and `LayeringTest` stays green. Everything else it needs
 * comes through `OrderQueryContract`, a Core port.
 *
 * **event_id = payment uuid**, the same value the browser pixel sends as
 * `eventID` on `/odeme/sonuc`, so Meta dedups the consented shopper and still
 * counts the un-consented one the pixel never saw.
 *
 * **RUNS AFTER COMMIT** (the event is dispatched from `BaseAction::after()`), so
 * it never reports a payment a later failure rolled back. It builds the payload
 * synchronously and hands the network call to a queued job — the PayTR callback
 * must not wait on Graph.
 *
 * **INERT UNLESS ENABLED.** The config gate returns before any query, so a booted
 * Marketing module with no token does nothing.
 */
final class SendMetaPurchase
{
    public function __construct(private readonly OrderQueryContract $orders) {}

    public function handle(object $event): void
    {
        if (! (bool) config('marketing.meta.enabled')) {
            return;
        }

        $paymentUuid = (string) data_get($event, 'paymentUuid');
        $checkoutGroupUuid = (string) data_get($event, 'checkoutGroupUuid');
        $amountMinor = (int) data_get($event, 'amountMinor');
        $currencyCode = (string) data_get($event, 'currencyCode');
        /** @var array<int, string> $orderUuids */
        $orderUuids = array_values((array) data_get($event, 'orderUuids', []));

        if ($paymentUuid === '' || $orderUuids === []) {
            return;
        }

        $customer = $this->orders->checkoutGroupCustomer($checkoutGroupUuid);
        $email = $customer['email'] ?? null;

        $contentIds = [];
        $contents = [];

        foreach ($orderUuids as $orderUuid) {
            foreach ($this->orders->orderLines((string) $orderUuid) as $line) {
                $productUuid = $line['product_uuid'];
                $contentIds[] = $productUuid;
                $contents[] = [
                    'id' => $productUuid,
                    'quantity' => (int) $line['quantity'],
                    'item_price' => $this->decimal((int) $line['unit_price_minor']),
                ];
            }
        }

        SendMetaConversionJob::dispatch(new PurchaseConversionDTO(
            eventId: $paymentUuid,
            valueMinor: $amountMinor,
            currencyCode: $currencyCode,
            email: $email,
            contentIds: array_values(array_unique($contentIds)),
            contents: $contents,
            eventTime: now()->getTimestamp(),
        ));
    }

    /**
     * Minor units to a decimal string — never a float (ADR-005).
     */
    private function decimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) abs($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
