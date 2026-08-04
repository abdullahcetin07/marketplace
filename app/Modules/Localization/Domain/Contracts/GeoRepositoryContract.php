<?php

declare(strict_types=1);

namespace App\Modules\Localization\Domain\Contracts;

use App\Modules\Localization\Domain\Models\GeoDistrict;
use App\Modules\Localization\Domain\Models\GeoNeighborhood;
use App\Modules\Localization\Domain\Models\GeoProvince;
use Illuminate\Support\Collection;

/**
 * Read port for the administrative geography cascade (ADR-056 amendment).
 *
 * THREE READS, EACH "THE CHILDREN OF ONE PARENT", because that is the only shape
 * an address form asks in: pick an il, then its ilçe, then its mahalle. There is
 * deliberately no "all neighbourhoods" method — 73,000 rows is not a payload, and
 * a port that can express it is one somebody eventually calls.
 *
 * RESOLVED BY NAME AS WELL AS BY UUID, which is unusual here and follows from
 * ADR-056: an address stores the NAME, so the client holding a saved address has
 * a name and no id. Requiring an id would mean it could not re-open its own
 * saved address in the cascade.
 *
 * NAME MATCHING IS FORGIVING ON PURPOSE — see the implementation. Turkish names
 * arrive with and without circumflexes ("Kâhta"/"Kahta"), with dotted and dotless
 * i, in either case. Exact-only matching would reject strings this platform's own
 * frontend has already saved.
 *
 * SORTED WITH A TURKISH COLLATOR, not by SQL. `ORDER BY name` is wrong on both of
 * this project's engines — SQLite compares bytes, and Postgres's default
 * collation is not `tr` — which puts İstanbul after Isparta and Çanakkale after
 * Zonguldak. The result sets are one parent's children, so ordering them in PHP
 * costs nothing and is correct on every driver.
 *
 * @see App\Modules\Localization\Infrastructure\Repositories\GeoRepository
 * @see docs/Architecture_Decision_Record.md ADR-056
 */
interface GeoRepositoryContract
{
    /**
     * Active provinces of a country, ISO-2, Turkish-sorted.
     *
     * @return Collection<int, GeoProvince>
     */
    public function provinces(string $countryIso2): Collection;

    /**
     * Active districts of a province, Turkish-sorted; empty when the province
     * cannot be resolved.
     *
     * @param string $province a name or a uuid
     *
     * @return Collection<int, GeoDistrict>
     */
    public function districts(string $province, string $countryIso2): Collection;

    /**
     * Active neighbourhoods of a district, Turkish-sorted; empty when the
     * district cannot be resolved.
     *
     * THE PROVINCE IS OPTIONAL BUT WANTED. District names repeat across the
     * country — there are several "Merkez", "Yenişehir" and "Çarşamba" — so
     * without it this answers for whichever one matched first. With it, the
     * answer is unambiguous.
     *
     * @param string $district a name or a uuid
     * @param string|null $province a name or a uuid
     *
     * @return Collection<int, GeoNeighborhood>
     */
    public function neighborhoods(string $district, ?string $province, string $countryIso2): Collection;
}
