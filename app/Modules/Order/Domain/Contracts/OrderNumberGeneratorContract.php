<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Contracts;

/**
 * Produces the human-facing order number (§2.3).
 *
 * A CONTRACT, like Catalog's SKU generator and Store's slug generator, and for
 * the same reason: the aggregate must not encode the scheme. An order number is
 * the one identifier that ends up printed on an invoice and read down a phone, so
 * it is exactly the kind of thing a business changes its mind about — per-year
 * sequences, a branch prefix, a checksum digit. Every one of those should be a
 * container binding, not an edit to the action that places orders.
 *
 * IT IS NOT THE UUID. The uuid is the machine identifier that crosses boundaries
 * (non-negotiable #7); this is for people. Using one for both would ask a customer
 * to read out 36 hex characters.
 *
 * @see App\Modules\Order\Infrastructure\Generators\DefaultOrderNumberGenerator
 */
interface OrderNumberGeneratorContract
{
    /**
     * A globally-unique order number.
     */
    public function generate(): string;
}
