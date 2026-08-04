<?php

declare(strict_types=1);

namespace App\Modules\Order\Infrastructure\Queries;

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Domain\Models\OrderLine;
use Illuminate\Support\Facades\DB;

/**
 * Order's implementation of the downstream read port (§5).
 *
 * RETURNS SCALARS AND PLAIN ARRAYS, never models — the discipline every Core
 * query contract keeps, so Payment and Shipping cannot reach through the port
 * into an order's internals and start driving its lifecycle.
 *
 * NO CALLER EXISTS YET, and that is deliberate rather than premature: publishing
 * the port with the module means "how does another context read an order" is
 * already answered when Payment arrives, instead of being decided under deadline
 * by whoever needs it first. Inventory's contracts shipped the same way a sprint
 * early, and Order — their first real caller — found them ready.
 *
 * @see App\Core\Domain\Contracts\OrderQueryContract
 * @see docs/modules/Order.md §5
 */
final class OrderQuery implements OrderQueryContract
{
    public function orderExists(string $orderUuid): bool
    {
        return Order::query()->where('uuid', $orderUuid)->exists();
    }

    public function orderStatus(string $orderUuid): ?string
    {
        $status = Order::query()->where('uuid', $orderUuid)->value('status');

        /*
        | A STRING, not the enum: typing this with `OrderStatus` would make every
        | consumer import the module the port exists to avoid importing.
        |
        | `value()` comes back as the CAST value, so this is already an enum
        | instance and unwrapping it is the whole job — reading it as a string
        | would silently answer null for every order that exists.
        */
        return $status instanceof OrderStatus ? $status->value : null;
    }

    /**
     * @return array<int, string>
     */
    public function ordersForCheckoutGroup(string $checkoutGroupUuid): array
    {
        return Order::query()
            ->inCheckoutGroup($checkoutGroupUuid)
            ->orderBy('id')
            ->pluck('uuid')
            ->all();
    }

    /**
     * Added for Payment (2026-08-04) — see the contract for why a Payment row
     * keyed to a GROUP still has to know whose money it is.
     *
     * THE EMAIL COMES FROM THE ORDER'S OWN CUSTOMER RECORD, read through the
     * users table by id rather than joined: Order holds the ADR-040 pair and no
     * relation to `User`, and the PSP needs an address to send a receipt to.
     *
     * @return array{id: int, uuid: string, email: string}|null
     */
    public function checkoutGroupCustomer(string $checkoutGroupUuid): ?array
    {
        $order = Order::query()
            ->inCheckoutGroup($checkoutGroupUuid)
            ->orderBy('id')
            ->first(['customer_id', 'customer_uuid']);

        if ($order === null) {
            return null;
        }

        $email = DB::table('users')->where('id', $order->customer_id)->value('email');

        return [
            'id' => (int) $order->customer_id,
            'uuid' => (string) $order->customer_uuid,
            // Empty rather than null when the account has gone: a payment is not
            // worth refusing over a missing receipt address, and the PSP will take
            // the charge either way.
            'email' => is_string($email) ? $email : '',
        ];
    }

    /**
     * Added for Payment (2026-08-04) — the PSP basket, and from P2 the commission
     * resolver's input.
     *
     * @return array<int, array{variant_uuid: string, product_uuid: string, title: string, quantity: int, unit_price_minor: int, line_total_minor: int, tax_rate: string}>
     */
    public function orderLines(string $orderUuid): array
    {
        $order = Order::query()->with('lines')->where('uuid', $orderUuid)->first();

        if ($order === null) {
            return [];
        }

        return $order->lines
            ->map(static fn (OrderLine $line): array => [
                'variant_uuid' => $line->variant_uuid,
                'product_uuid' => $line->product_uuid,
                // The title AS BOUGHT (ADR-053), which is what belongs on a
                // payment page — a shopper must recognise what they are paying
                // for, even if the catalogue has since renamed it.
                'title' => $line->product_title,
                'quantity' => $line->quantity,
                'unit_price_minor' => $line->unit_price_minor,
                'line_total_minor' => $line->line_total_minor,
                'tax_rate' => $line->tax_rate,
            ])
            ->values()
            ->all();
    }

    /**
     * Added for Payment (2026-08-04) — see the contract for why the reference
     * FORMAT stays in this module.
     *
     * BUILT BY THE AGGREGATE, not by string concatenation here: `Order::
     * reservationReferenceFor()` is the one definition of the key, and the whole
     * reason this method exists is so nothing outside Order ever writes that
     * colon.
     *
     * @return array<int, string>
     */
    public function reservationReferencesFor(string $orderUuid): array
    {
        $order = Order::query()->with('lines')->where('uuid', $orderUuid)->first();

        if ($order === null) {
            return [];
        }

        return $order->lines
            ->map(static fn (OrderLine $line): string => $order->reservationReferenceFor($line->variant_uuid))
            ->values()
            ->all();
    }

    /**
     * Added for Payment's seller ledger (2026-08-04) — see the contract for why
     * this reads the frozen snapshot rather than recomputing.
     *
     * NULL COMMISSION MEANS "NOT SETTLED YET", not "no commission". It is null on
     * every line of an unpaid order and on every line placed before the ADR-061
     * migration, and the two are indistinguishable from here — which is why the
     * caller is told to treat null as unsettled rather than as zero.
     *
     * @return array{selling_org_uuid: string, commission_minor: int|null}|null
     */
    public function orderSettlement(string $orderUuid): ?array
    {
        $order = Order::query()->with('lines')->where('uuid', $orderUuid)->first();

        if ($order === null) {
            return null;
        }

        $lines = $order->lines;

        return [
            'selling_org_uuid' => $order->selling_org_uuid,
            // Null unless EVERY line has been frozen: a partially settled order is
            // not a smaller commission, it is an order somebody should look at.
            'commission_minor' => $lines->isNotEmpty() && $lines->every(
                static fn (OrderLine $line): bool => $line->commission_minor !== null,
            )
                ? (int) $lines->sum('commission_minor')
                : null,
        ];
    }

    /**
     * @return array{items_total_minor: int, tax_total_minor: int, grand_total_minor: int, currency_code: string}|null
     */
    public function orderTotals(string $orderUuid): ?array
    {
        $order = Order::query()->with('currency')->where('uuid', $orderUuid)->first();

        if ($order === null) {
            return null;
        }

        return [
            // Minor units plus a code (non-negotiable #6). Rendering it as a
            // decimal string is the caller's presentation concern.
            'items_total_minor' => $order->items_total_minor,
            /*
            | The tax as it was EXTRACTED AT PLACEMENT, not recomputed. The rate
            | that produced it is frozen on the lines (ADR-053/055) and may since
            | have been revised, so recomputing is not guaranteed to reproduce the
            | invoice — which is the one thing this number has to do.
            */
            'tax_total_minor' => $order->tax_total_minor,
            'grand_total_minor' => $order->grand_total_minor,
            'currency_code' => $order->currency->code,
        ];
    }
}
