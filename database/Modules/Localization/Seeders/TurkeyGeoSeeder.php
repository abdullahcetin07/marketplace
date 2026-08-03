<?php

declare(strict_types=1);

namespace Database\Modules\Localization\Seeders;

use App\Modules\Localization\Domain\Models\Country;
use App\Modules\Localization\Domain\Models\GeoDistrict;
use App\Modules\Localization\Domain\Models\GeoNeighborhood;
use App\Modules\Localization\Domain\Models\GeoProvince;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turkey's il / ilçe / mahalle — 81, 973 and ~73,300 rows (ADR-056 amendment).
 *
 * WHY THE DATA IS GZIPPED IN THE REPO. It is 1.5 MB of JSON and 400 KB
 * compressed, and PHP reads gzip without a dependency. The alternative — fetching
 * it at seed time — would make a deploy depend on a third-party host still
 * existing and still serving the same shape, which is exactly the failure that
 * cannot be diagnosed at 2am. The registry changes a few times a year; refreshing
 * it is a deliberate act with a diff to review, not something that happens
 * silently on somebody's laptop.
 *
 * SOURCE: `muratgozel/turkey-neighbourhoods` (MIT), derived from the NVİ address
 * registry. Names were normalised on the way in — the redundant "Mah" label
 * dropped ("Caferağa Mah" → "Caferağa"), whitespace collapsed, and the first
 * letter of the disambiguating parenthetical capitalised ("(konalga Köyü)" →
 * "(Konalga Köyü)"). "Köyü", "Beldesi" and "Yaylası" are kept: they are the place
 * TYPE, not a redundant label, and a village and a neighbourhood of the same name
 * are different places.
 *
 * TWO NAMES WERE CORRECTED against the source: **Kâhta** (Adıyaman) and
 * **Kâzımkarabekir** (Karaman) carry the circumflex the registry uses and the
 * dataset had dropped. See `GeoRepository::fold()` — resolution is
 * diacritic-insensitive either way, so a client holding the other spelling still
 * matches.
 *
 * IDEMPOTENT BY (parent, name), which is what the UNIQUE indexes enforce. Running
 * it twice inserts nothing and reactivates nothing — it is safe on a live
 * database, and it never touches `is_active`, so a neighbourhood an operator
 * DEACTIVATED stays deactivated. That is the property that matters: a re-seed
 * must not silently undo an operator's decision.
 *
 * CHUNKED UPSERTS, one transaction. 73,300 individual inserts is minutes of
 * round trips; chunked upserts are seconds. The chunk size is small enough that
 * no driver hits its bound-parameter ceiling.
 *
 * Not registered in `DatabaseSeeder`: an operator runs it, and a test that needs
 * geography builds the three rows it is testing. Seeding 73k rows into every test
 * database would make the suite unusable.
 *
 *     php artisan db:seed --class="Database\Modules\Localization\Seeders\TurkeyGeoSeeder"
 *
 * @see docs/Architecture_Decision_Record.md ADR-056
 * @see docs/localization.md
 */
final class TurkeyGeoSeeder extends Seeder
{
    public const string COUNTRY_ISO2 = 'TR';

    /**
     * Rows per upsert. Postgres allows 65,535 bound parameters per statement and
     * each row here binds five, so 1,000 leaves an order of magnitude of room —
     * deliberately, because the ceiling is a hard error rather than a slowdown.
     */
    private const int CHUNK = 1_000;

    public function run(): void
    {
        $country = Country::query()->where('iso2', self::COUNTRY_ISO2)->first();

        if ($country === null) {
            // Localization seeds the countries table; without TR there is
            // nothing to hang this geography from, and inventing the row here
            // would put a half-populated country in the lookup.
            throw new RuntimeException(
                'Cannot seed TR geography: the TR country row is missing. Run the Localization seeder first.',
            );
        }

        $data = $this->dataset();

        DB::transaction(function () use ($country, $data): void {
            $provinceIds = $this->seedProvinces((int) $country->getKey(), $data);
            $districtIds = $this->seedDistricts($provinceIds, $data);

            $this->seedNeighborhoods($districtIds, $data);
        });
    }

    /**
     * @return array<string, array{code: string, districts: array<string, array<int, string>>}>
     */
    private function dataset(): array
    {
        $path = database_path('Modules/Localization/data/tr-geo.json.gz');

        $raw = @file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException("TR geo dataset not found at {$path}.");
        }

        $json = @gzdecode($raw);

        if ($json === false) {
            throw new RuntimeException("TR geo dataset at {$path} is not readable gzip.");
        }

        /** @var array<string, array{code: string, districts: array<string, array<int, string>>}> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @param  array<string, array{code: string, districts: array<string, array<int, string>>}>  $data
     * @return array<string, int>  province name => id
     */
    private function seedProvinces(int $countryId, array $data): array
    {
        $rows = [];

        foreach ($data as $name => $node) {
            $rows[] = [
                'uuid' => (string) Str::uuid(),
                'country_id' => $countryId,
                'name' => $name,
                'code' => $node['code'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        /*
        | UPDATE ONLY `code` ON CONFLICT — never `is_active`, and never `uuid`.
        | The uuid is a public identifier that other systems may already have
        | quoted, and `is_active` is an operator's decision this must not undo.
        */
        GeoProvince::query()->upsert($rows, ['country_id', 'name'], ['code']);

        /** @var array<string, int> $ids */
        $ids = GeoProvince::query()
            ->where('country_id', $countryId)
            ->pluck('id', 'name')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $ids;
    }

    /**
     * @param  array<string, int>  $provinceIds
     * @param  array<string, array{code: string, districts: array<string, array<int, string>>}>  $data
     * @return array<string, int>  "province|district" => id
     */
    private function seedDistricts(array $provinceIds, array $data): array
    {
        $rows = [];

        foreach ($data as $province => $node) {
            foreach (array_keys($node['districts']) as $district) {
                $rows[] = [
                    'uuid' => (string) Str::uuid(),
                    'geo_province_id' => $provinceIds[$province],
                    'name' => $district,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            // Nothing to update on conflict — the row IS the (parent, name) pair.
            // An empty update list makes this a genuine insert-or-ignore.
            GeoDistrict::query()->upsert($chunk, ['geo_province_id', 'name'], ['name']);
        }

        // Keyed by province NAME, which is what the dataset loops hold — district
        // names repeat across the country ("Merkez" 50-odd times), so the parent
        // has to be part of the key or the map collapses.
        $provinceNames = array_flip($provinceIds);
        $ids = [];

        GeoDistrict::query()
            ->whereIn('geo_province_id', array_values($provinceIds))
            ->select(['id', 'geo_province_id', 'name'])
            ->chunkById(5_000, function ($districts) use (&$ids, $provinceNames): void {
                foreach ($districts as $district) {
                    $province = $provinceNames[$district->geo_province_id] ?? null;

                    if ($province !== null) {
                        $ids[$province.'|'.$district->name] = (int) $district->id;
                    }
                }
            });

        return $ids;
    }

    /**
     * @param  array<string, int>  $districtIds
     * @param  array<string, array{code: string, districts: array<string, array<int, string>>}>  $data
     */
    private function seedNeighborhoods(array $districtIds, array $data): void
    {
        $rows = [];

        foreach ($data as $province => $node) {
            foreach ($node['districts'] as $district => $neighborhoods) {
                $districtId = $districtIds[$province.'|'.$district] ?? null;

                if ($districtId === null) {
                    continue;
                }

                foreach ($neighborhoods as $neighborhood) {
                    $rows[] = [
                        'uuid' => (string) Str::uuid(),
                        'geo_district_id' => $districtId,
                        'name' => $neighborhood,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            GeoNeighborhood::query()->upsert($chunk, ['geo_district_id', 'name'], ['name']);
        }
    }
}
