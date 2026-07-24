<?php

declare(strict_types=1);

namespace App\Modules\Store\Infrastructure\Generators;

use App\Modules\Store\Domain\Contracts\StoreNumberGeneratorContract;
use App\Modules\Store\Domain\Contracts\StoreRepositoryContract;
use Illuminate\Support\Str;

/**
 * A random, collision-checked store number (`ST-XXXXXXXX`).
 *
 * Random rather than sequential so the code does not leak how many stores exist;
 * short enough to quote. The prefix is config (ADR-025). Uniqueness is asked of
 * the repository and re-rolled on collision — rare, since creation is an
 * admin-approved event.
 *
 * @see App\Modules\Store\Domain\Contracts\StoreNumberGeneratorContract
 */
final class RandomStoreNumberGenerator implements StoreNumberGeneratorContract
{
    public function __construct(private readonly StoreRepositoryContract $stores) {}

    public function generate(): string
    {
        $prefix = (string) config('marketplace.store.number_prefix', 'ST');

        do {
            $number = $prefix.'-'.Str::upper(Str::random(8));
        } while ($this->stores->storeNumberExists($number));

        return $number;
    }
}
