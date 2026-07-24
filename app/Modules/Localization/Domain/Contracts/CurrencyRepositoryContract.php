<?php

declare(strict_types=1);

namespace App\Modules\Localization\Domain\Contracts;

use App\Modules\Localization\Domain\Models\Currency;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read port for currencies.
 *
 * @see App\Modules\Localization\Infrastructure\Repositories\CurrencyRepository
 */
interface CurrencyRepositoryContract
{
    /**
     * The base currency every exchange rate is relative to. Throws if unseeded.
     */
    public function default(): Currency;

    public function findByCode(string $code): ?Currency;

    /**
     * @return Collection<int, Currency>
     */
    public function active(): Collection;

    public function flush(?Currency $currency = null): void;
}
