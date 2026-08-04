<?php

declare(strict_types=1);

namespace App\Modules\Localization\Infrastructure\Repositories;

use App\Modules\Localization\Domain\Contracts\GeoRepositoryContract;
use App\Modules\Localization\Domain\Models\GeoDistrict;
use App\Modules\Localization\Domain\Models\GeoNeighborhood;
use App\Modules\Localization\Domain\Models\GeoProvince;
use Collator;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Cached, Turkish-sorted geography reads. @see LanguageRepository for the
 * ADR-019 reasoning about why the caching lives here and not on the models.
 *
 * THE MOST CACHEABLE DATA ON THE PLATFORM. Turkey's provinces changed last in
 * 1999; a district list changes a few times a decade. A 24-hour TTL is short for
 * what this is, and it is only that short so an operator's edit shows up the same
 * day without anyone having to know a flush command exists.
 *
 * CACHED PER PARENT, not as one blob: a form asks for the districts of ONE
 * province, and caching all 973 to answer for 30 would put the whole country in
 * memory on every address form.
 *
 * WHY THE SORT IS IN PHP. Turkish alphabetical order interleaves letters that
 * neither engine sorts correctly by default — ç after c, ğ after g, ı before i,
 * ö after o, ş after s, ü after u — so `ORDER BY name` puts İstanbul after
 * Isparta on SQLite and somewhere else again on a default-collation Postgres. A
 * `tr` collator gives one answer on both. It is affordable precisely because
 * every read here is one parent's children.
 *
 * @see App\Modules\Localization\Domain\Contracts\GeoRepositoryContract
 */
final class GeoRepository implements GeoRepositoryContract
{
    /** A day: long for reference data, short enough that an edit lands today. */
    private const int TTL = 86_400;

    private ?Collator $collator = null;

    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * @return Collection<int, GeoProvince>
     */
    public function provinces(string $countryIso2): Collection
    {
        $iso2 = mb_strtoupper($countryIso2);

        /** @var Collection<int, GeoProvince> $provinces */
        $provinces = $this->cache->remember(
            "localization:geo:provinces:{$iso2}",
            self::TTL,
            fn (): Collection => $this->sorted(
                GeoProvince::query()
                    ->active()
                    ->whereHas('country', static fn ($query) => $query->where('iso2', $iso2))
                    ->get()
                    ->toBase(),
            ),
        );

        return $provinces;
    }

    /**
     * @return Collection<int, GeoDistrict>
     */
    public function districts(string $province, string $countryIso2): Collection
    {
        $resolved = $this->resolveProvince($province, $countryIso2);

        if ($resolved === null) {
            /** @var Collection<int, GeoDistrict> $empty */
            $empty = new Collection;

            return $empty;
        }

        /** @var Collection<int, GeoDistrict> $districts */
        $districts = $this->cache->remember(
            "localization:geo:districts:{$resolved->getKey()}",
            self::TTL,
            fn (): Collection => $this->sorted(
                GeoDistrict::query()
                    ->active()
                    ->where('geo_province_id', $resolved->getKey())
                    ->get()
                    ->toBase(),
            ),
        );

        return $districts;
    }

    /**
     * @return Collection<int, GeoNeighborhood>
     */
    public function neighborhoods(string $district, ?string $province, string $countryIso2): Collection
    {
        $resolved = $this->resolveDistrict($district, $province, $countryIso2);

        if ($resolved === null) {
            /** @var Collection<int, GeoNeighborhood> $empty */
            $empty = new Collection;

            return $empty;
        }

        /** @var Collection<int, GeoNeighborhood> $neighborhoods */
        $neighborhoods = $this->cache->remember(
            "localization:geo:neighborhoods:{$resolved->getKey()}",
            self::TTL,
            fn (): Collection => $this->sorted(
                GeoNeighborhood::query()
                    ->active()
                    ->where('geo_district_id', $resolved->getKey())
                    ->get()
                    ->toBase(),
            ),
        );

        return $neighborhoods;
    }

    /*
    |--------------------------------------------------------------------------
    | Resolution
    |--------------------------------------------------------------------------
    |
    | A client arrives holding whatever its saved address holds — a NAME, in
    | whatever spelling it was typed or picked in (ADR-056 keeps the address
    | free text). So a uuid is tried first because it is unambiguous, then the
    | name exactly, then the name folded.
    */

    private function resolveProvince(string $province, string $countryIso2): ?GeoProvince
    {
        $iso2 = mb_strtoupper($countryIso2);

        $query = GeoProvince::query()
            ->active()
            ->whereHas('country', static fn ($q) => $q->where('iso2', $iso2));

        $match = (clone $query)
            ->where(fn ($q) => $this->matchUuidOrName($q, $province))
            ->first();

        if ($match !== null) {
            return $match;
        }

        // The folded pass is a scan of ~81 rows and only runs when the exact
        // match failed, so it costs nothing on the common path.
        return $this->foldedMatch($query->get()->toBase(), $province);
    }

    private function resolveDistrict(string $district, ?string $province, string $countryIso2): ?GeoDistrict
    {
        $query = GeoDistrict::query()->active();

        if ($province !== null && $province !== '') {
            $parent = $this->resolveProvince($province, $countryIso2);

            // A province was NAMED and did not resolve: answering for a
            // same-named district in some other province would be worse than
            // answering nothing, because the client would silently populate a
            // dropdown for the wrong place.
            if ($parent === null) {
                return null;
            }

            $query->where('geo_province_id', $parent->getKey());
        } else {
            $iso2 = mb_strtoupper($countryIso2);

            $query->whereHas(
                'province',
                static fn ($q) => $q->whereHas('country', static fn ($c) => $c->where('iso2', $iso2)),
            );
        }

        $match = (clone $query)
            ->where(fn ($q) => $this->matchUuidOrName($q, $district))
            ->first();

        if ($match !== null) {
            return $match;
        }

        return $this->foldedMatch($query->get()->toBase(), $district);
    }

    /**
     * Match a value against `uuid` OR `name` — and only touch `uuid` when the
     * value could actually BE one.
     *
     * THIS GUARD IS NOT DEFENSIVE POLISH, IT IS THE QUERY WORKING AT ALL.
     * `uuid` is a native `uuid` column on PostgreSQL, and comparing it to
     * "İstanbul" is not a non-match — it is `SQLSTATE[22P02] invalid input
     * syntax for type uuid`, a 500 on the most ordinary call this API has. On
     * SQLite the same column is text, the comparison quietly returns false, and
     * every test passes.
     *
     * THE PLATFORM HAS BEEN BITTEN BY EXACTLY THIS ONCE BEFORE: ADR-049's
     * `stock_reservations.reference_uuid` took a non-uuid key from Order and
     * broke every checkout in production while 1,088 SQLite tests stayed green
     * (see that ADR's 2026-07-31 amendment). The lesson recorded there was to
     * cover the path on a real PostgreSQL, which `tests/Integration` now does for
     * this one too.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<*>  $query
     */
    private function matchUuidOrName(mixed $query, string $value): mixed
    {
        if (Str::isUuid($value)) {
            return $query->where('uuid', $value)->orWhere('name', $value);
        }

        return $query->where('name', $value);
    }

    /**
     * The first row whose folded name equals the folded needle.
     *
     * @template TModel of GeoProvince|GeoDistrict
     *
     * @param Collection<int, TModel> $rows
     *
     * @return TModel|null
     */
    private function foldedMatch(Collection $rows, string $needle): mixed
    {
        $folded = $this->fold($needle);

        return $rows->first(fn (GeoProvince|GeoDistrict $row): bool => $this->fold($row->name) === $folded);
    }

    /**
     * Reduce a Turkish place name to something two spellings of it agree on.
     *
     * THE FOUR NAMES THAT MADE THIS NECESSARY are real: this platform's own
     * frontend bundle ships "Kâhta" and "Kâzımkarabekir" where the seeded
     * registry writes them without the circumflex, and "Aricak" where the
     * registry has "Arıcak". Those are the same places, and a saved address
     * naming one must still open its own dropdown.
     *
     * CASE IS FOLDED THE TURKISH WAY: `mb_strtolower('İ')` is not 'i' by
     * default, so the dotted capital is mapped explicitly. Getting this wrong
     * would make "İSTANBUL" fail to match "İstanbul", which is the exact bug the
     * fold exists to prevent.
     */
    private function fold(string $value): string
    {
        $value = str_replace(
            ['â', 'Â', 'î', 'Î', 'û', 'Û', 'İ', 'I', 'ı'],
            ['a', 'a', 'i', 'i', 'u', 'u', 'i', 'i', 'i'],
            $value,
        );

        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($value))) ?? $value;
    }

    /**
     * @template TModel of GeoProvince|GeoDistrict|GeoNeighborhood
     *
     * @param Collection<int, TModel> $rows
     *
     * @return Collection<int, TModel>
     */
    private function sorted(Collection $rows): Collection
    {
        $collator = $this->collator ??= new Collator('tr_TR');

        return $rows
            ->sort(static fn ($a, $b): int => $collator->compare($a->name, $b->name) ?: 0)
            ->values();
    }
}
