<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\Contracts;

/**
 * Produces a store's public number (`ST-XXXXXXXX`-style).
 *
 * A CONTRACT so the numbering scheme is replaceable without touching the Store
 * aggregate. Today's rule is a random, collision-checked code; a later scheme
 * (sequential, region-prefixed, check-digit) swaps the implementation only.
 *
 * @see App\Modules\Store\Infrastructure\Generators\RandomStoreNumberGenerator
 */
interface StoreNumberGeneratorContract
{
    /**
     * A globally-unique store number.
     */
    public function generate(): string;
}
