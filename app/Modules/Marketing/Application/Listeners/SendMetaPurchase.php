<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Application\Listeners;

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Modules\Marketing\Application\Jobs\SendMetaConversionJob;
use App\Modules\Marketing\Domain\DTOs\PurchaseConversionDTO;
use App\Modules\Marketing\Domain\Models\CheckoutSignal;
use Throwable;

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
 *
 * **IT NEVER BREAKS A PAYMENT.** This runs synchronously inside the PayTR
 * callback's `after()`, alongside the listeners that confirm orders and open
 * shipments. An exception escaping here would 500 the callback and skip every
 * listener registered after this one — a lost ad conversion turned into an
 * unshipped paid order. So any failure (a query, a Redis outage on dispatch) is
 * reported and swallowed: the conversion is expendable, the payment is not.
 */
final class SendMetaPurchase
{
    public function __construct(private readonly OrderQueryContract $orders) {}

    public function handle(object $event): void
    {
        if (! (bool) config('marketing.meta.enabled')) {
            return;
        }

        try {
            $this->dispatchPurchase($event);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function dispatchPurchase(object $event): void
    {

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

        // Captured on the pay request by CaptureCheckoutSignals; absent when that
        // request predates the capture or the shopper paid from another path.
        $signal = CheckoutSignal::query()
            ->where('checkout_group_uuid', $checkoutGroupUuid)
            ->first();

        SendMetaConversionJob::dispatch(new PurchaseConversionDTO(
            eventId: $paymentUuid,
            valueMinor: $amountMinor,
            currencyCode: $currencyCode,
            email: $email,
            contentIds: array_values(array_unique($contentIds)),
            contents: $contents,
            eventTime: now()->getTimestamp(),
            fbp: $signal?->fbp,
            fbc: $signal?->fbc,
            clientIp: $signal?->client_ip,
            clientUserAgent: $signal?->client_user_agent,
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
