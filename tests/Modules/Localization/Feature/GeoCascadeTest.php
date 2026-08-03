<?php

declare(strict_types=1);

use App\Modules\Localization\Domain\Contracts\GeoRepositoryContract;
use App\Modules\Localization\Domain\Models\Country;
use App\Modules\Localization\Domain\Models\GeoDistrict;
use App\Modules\Localization\Domain\Models\GeoNeighborhood;
use App\Modules\Localization\Domain\Models\GeoProvince;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The address form's il → ilçe → mahalle cascade (ADR-056 amendment)
|--------------------------------------------------------------------------
|
| Reference data for a dropdown, and NOT a validator — that distinction is what
| most of this file protects. ADR-056 keeps a customer address country-agnostic
| and free text; these tables exist so a Turkish shopper can pick rather than
| type, and they get no say in what a stored address may contain.
|
| The rules pinned here:
|
|   ANONYMOUS        a guest fills in a delivery address before signing up
|   ONE PARENT ONLY  there is no "all neighbourhoods" read — 73k rows is not a payload
|   NAME OR UUID     a saved address holds a NAME, so a name must resolve
|   FORGIVING        "Kahta"/"Kâhta", "Aricak"/"Arıcak", any case — same place
|   TURKISH ORDER    ç after c, ı before i, ş after s — no SQL engine does this
|   EMPTY, NOT 404   an unresolvable parent means "no options", not "broken"
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

/**
 * A minimal two-province slice of Turkey.
 *
 * Named for this file because Pest shares ONE global function namespace.
 *
 * @return array{province: GeoProvince, district: GeoDistrict}
 */
function geoFixture(): array
{
    $tr = Country::query()->where('iso2', 'TR')->firstOrFail();

    $istanbul = GeoProvince::query()->create([
        'country_id' => $tr->getKey(),
        'name' => 'İstanbul',
        'code' => '34',
    ]);

    $kadikoy = GeoDistrict::query()->create([
        'geo_province_id' => $istanbul->getKey(),
        'name' => 'Kadıköy',
    ]);

    foreach (['Caferağa', 'Acıbadem', 'Çilehane', 'Erenköy'] as $name) {
        GeoNeighborhood::query()->create([
            'geo_district_id' => $kadikoy->getKey(),
            'name' => $name,
        ]);
    }

    // A second province whose district shares a name with nothing here, so the
    // scoping assertions have something to fail against.
    $adiyaman = GeoProvince::query()->create([
        'country_id' => $tr->getKey(),
        'name' => 'Adıyaman',
        'code' => '02',
    ]);

    // The circumflex the official registry uses and this platform's frontend
    // bundle ships — one of the four names the two sources spell differently.
    GeoDistrict::query()->create(['geo_province_id' => $adiyaman->getKey(), 'name' => 'Kâhta']);

    return ['province' => $istanbul, 'district' => $kadikoy];
}

it('serves the cascade to anyone, with no session', function (): void {
    geoFixture();

    // A guest types a delivery address before they have an account, and these
    // are divisions the state publishes. A login here would protect nothing.
    $this->getJson('/api/v1/geo/provinces')->assertOk();
    $this->getJson('/api/v1/geo/districts?province=İstanbul')->assertOk();
    $this->getJson('/api/v1/geo/neighborhoods?district=Kadıköy&province=İstanbul')->assertOk();
});

it('returns a uuid and a name, and the plate code only on a province', function (): void {
    geoFixture();

    /** @var array<int, array<string, mixed>> $provinces */
    $provinces = $this->getJson('/api/v1/geo/provinces')->json('data');
    $province = collect($provinces)->firstWhere('name', 'İstanbul');

    expect($province['code'])->toBe('34')
        // The public id is the uuid (non-negotiable #7) — never the row id.
        ->and($province['id'])->not->toBeNumeric();

    $neighborhood = $this->getJson('/api/v1/geo/neighborhoods?district=Kadıköy&province=İstanbul')
        ->json('data.0');

    // No `code` key at all rather than a null one: this payload is 73,300 rows
    // in production, and a column of nulls on it is pure weight.
    expect($neighborhood)->not->toHaveKey('code');
});

it('resolves a parent by name, which is what a saved address actually holds', function (): void {
    $fixture = geoFixture();

    // ADR-056 stores `city`/`district` as strings, so a client re-opening its own
    // saved address has names and no ids. Requiring an id would mean the cascade
    // could not reload the addresses this platform wrote.
    $byName = $this->getJson('/api/v1/geo/districts?province=İstanbul')->json('data');
    $byUuid = $this->getJson('/api/v1/geo/districts?province='.$fixture['province']->uuid)->json('data');

    expect($byName)->toBe($byUuid)->toHaveCount(1);
});

it('matches a name spelled with or without its circumflex, in any case', function (): void {
    geoFixture();

    /*
     * NOT A HYPOTHETICAL. This platform's own frontend bundle ships "Kâhta" and
     * "Kâzımkarabekir" where the seeded registry writes them plainly, and
     * "Aricak" where the registry has "Arıcak" — four real disagreements between
     * two lists already in this repo. A saved address naming one must still open
     * its own dropdown.
     */
    foreach (['Kâhta', 'Kahta', 'KAHTA', 'kâhta'] as $spelling) {
        $this->getJson('/api/v1/geo/neighborhoods?district='.$spelling.'&province=Adıyaman')
            ->assertOk();
    }

    foreach (['İstanbul', 'istanbul', 'İSTANBUL'] as $spelling) {
        expect($this->getJson('/api/v1/geo/districts?province='.$spelling)->json('data'))
            ->toHaveCount(1);
    }
});

it('orders places by the Turkish alphabet, which no engine does by default', function (): void {
    geoFixture();

    $names = array_column(
        $this->getJson('/api/v1/geo/neighborhoods?district=Kadıköy&province=İstanbul')->json('data'),
        'name',
    );

    /*
     * Ç SORTS AFTER C, not after Z. SQLite compares bytes and Postgres uses its
     * own default collation, so `ORDER BY name` gets this wrong on both — which
     * is why the ordering is done with a `tr` collator in the repository. A
     * shopper scrolling past Çilehane to find it at the bottom is the visible
     * symptom.
     */
    expect($names)->toBe(['Acıbadem', 'Caferağa', 'Çilehane', 'Erenköy']);
});

it('answers an unresolvable or missing parent with an empty list, not a 404', function (): void {
    geoFixture();

    // A saved address may name a district that has been renamed since, or one the
    // customer spelled their own way. "No options" is the honest answer and lets
    // a form fall back to free text; a 404 would look like a broken endpoint.
    expect($this->getJson('/api/v1/geo/districts?province=Atlantis')->assertOk()->json('data'))->toBe([])
        ->and($this->getJson('/api/v1/geo/neighborhoods?district=Atlantis')->assertOk()->json('data'))->toBe([])
        // And with NO parent at all: the parent is the whole query, so the
        // alternative would be "every district in the country".
        ->and($this->getJson('/api/v1/geo/districts')->assertOk()->json('data'))->toBe([])
        ->and($this->getJson('/api/v1/geo/neighborhoods')->assertOk()->json('data'))->toBe([]);
});

it('refuses to answer for a same-named district in the wrong province', function (): void {
    $fixture = geoFixture();
    $tr = Country::query()->where('iso2', 'TR')->firstOrFail();

    // "Merkez" exists in most provinces; so do Yenişehir and Çarşamba. Answering
    // for whichever matched first would populate a dropdown for another city and
    // nobody would notice until a parcel went there.
    $other = GeoProvince::query()->create(['country_id' => $tr->getKey(), 'name' => 'Ankara', 'code' => '06']);
    $otherKadikoy = GeoDistrict::query()->create(['geo_province_id' => $other->getKey(), 'name' => 'Kadıköy']);

    GeoNeighborhood::query()->create(['geo_district_id' => $otherKadikoy->getKey(), 'name' => 'Yanlış Mahalle']);
    GeoNeighborhood::query()->create(['geo_district_id' => $fixture['district']->getKey(), 'name' => 'Doğru Mahalle']);

    $names = array_column(
        $this->getJson('/api/v1/geo/neighborhoods?district=Kadıköy&province=İstanbul')->json('data'),
        'name',
    );

    expect($names)->toContain('Doğru Mahalle')
        ->and($names)->not->toContain('Yanlış Mahalle');

    // A province that was NAMED and did not resolve returns nothing, rather than
    // falling back to a country-wide search that would find the wrong Kadıköy.
    expect($this->getJson('/api/v1/geo/neighborhoods?district=Kadıköy&province=Atlantis')->json('data'))
        ->toBe([]);
});

it('hides a deactivated place at every level', function (): void {
    $fixture = geoFixture();

    /*
     * `is_active`, not deletion (ADR-015). Turkey merges and renames
     * neighbourhoods by decree several times a year, and addresses out there
     * still name the old one — so a retired place stops being OFFERED without the
     * row disappearing from under the addresses that reference it.
     */
    GeoNeighborhood::query()
        ->where('geo_district_id', $fixture['district']->getKey())
        ->where('name', 'Caferağa')
        ->update(['is_active' => false]);

    $names = array_column(
        $this->getJson('/api/v1/geo/neighborhoods?district=Kadıköy&province=İstanbul')->json('data'),
        'name',
    );

    expect($names)->not->toContain('Caferağa')->toHaveCount(3);

    $fixture['province']->update(['is_active' => false]);

    expect(array_column($this->getJson('/api/v1/geo/provinces')->json('data'), 'name'))
        ->not->toContain('İstanbul');
});

it('scopes provinces to their country', function (): void {
    geoFixture();

    $de = Country::query()->where('iso2', 'DE')->first();

    if ($de !== null) {
        GeoProvince::query()->create(['country_id' => $de->getKey(), 'name' => 'Bayern']);
    }

    $tr = array_column($this->getJson('/api/v1/geo/provinces?country=TR')->json('data'), 'name');

    // The tables are `geo_*`, not `tr_*`, and a generic name with a hidden
    // Turkish assumption is discovered by the second country rather than by
    // reading.
    expect($tr)->toContain('İstanbul');
    expect($tr)->not->toContain('Bayern');

    // An unknown country is an empty list, not a validation error — a 422 would
    // tell a probing client which countries the platform has seeded.
    $this->getJson('/api/v1/geo/provinces?country=ZZ')->assertOk()->assertJsonPath('data', []);
});

it('never queries the uuid column with something that is not a uuid', function (): void {
    $fixture = geoFixture();

    /*
     * THE GUARD FOR A BUG THIS PLATFORM HAS ALREADY SHIPPED ONCE. `uuid` is a
     * NATIVE uuid column on PostgreSQL: comparing it to "İstanbul" is not a
     * non-match, it is SQLSTATE[22P02] and a 500. On SQLite — where this test
     * runs — the column is text and the comparison quietly returns false, which
     * is exactly how ADR-049's reservation reference reached production and broke
     * every checkout while the whole suite stayed green.
     *
     * So this asserts on the SQL rather than on the response: the query log is
     * the only place the mistake is visible from SQLite.
     */
    DB::enableQueryLog();

    app(GeoRepositoryContract::class)->districts('İstanbul', 'TR');
    app(GeoRepositoryContract::class)->neighborhoods('Kadıköy', 'İstanbul', 'TR');

    $touchedUuid = collect(DB::getQueryLog())
        ->filter(static fn (array $entry): bool => str_contains((string) $entry['query'], '"uuid" ='))
        ->isNotEmpty();

    DB::disableQueryLog();

    expect($touchedUuid)->toBeFalse();

    // And the uuid path still works when the value IS one.
    DB::enableQueryLog();
    app(GeoRepositoryContract::class)->districts($fixture['province']->uuid, 'TR');
    $used = collect(DB::getQueryLog())
        ->contains(static fn (array $entry): bool => str_contains((string) $entry['query'], '"uuid" ='));
    DB::disableQueryLog();

    expect($used)->toBeTrue();
});
