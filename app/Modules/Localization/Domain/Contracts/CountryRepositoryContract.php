<?php

declare(strict_types=1);

namespace App\Modules\Localization\Domain\Contracts;

use App\Modules\Localization\Domain\Models\Country;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read port for countries.
 *
 * @see App\Modules\Localization\Infrastructure\Repositories\CountryRepository
 */
interface CountryRepositoryContract
{
    /**
     * The platform's home country. Nullable — unlike language and currency, the
     * platform can serve a request without one.
     */
    public function default(): ?Country;

    public function findByIso2(string $iso2): ?Country;

    /**
     * @return Collection<int, Country>
     */
    public function active(): Collection;

    public function flush(?Country $country = null): void;
}
