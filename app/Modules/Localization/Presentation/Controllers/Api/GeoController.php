<?php

declare(strict_types=1);

namespace App\Modules\Localization\Presentation\Controllers\Api;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Localization\Domain\Contracts\GeoRepositoryContract;
use App\Modules\Localization\Presentation\Resources\GeoPlaceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The address form's il → ilçe → mahalle cascade (ADR-056 amendment).
 *
 * UNAUTHENTICATED, like the country and timezone lists beside it. Turkey's
 * administrative divisions are published by the state; putting a login in front
 * of them would protect nothing and would stop a guest filling in a delivery
 * address before signing up.
 *
 * THREE ENDPOINTS, EACH "THE CHILDREN OF ONE PARENT". There is deliberately no
 * "everything" route: 73,300 neighbourhoods is not a payload, and a route that
 * can express it is one somebody eventually calls from a page load.
 *
 * A PARENT MAY BE NAMED OR IDENTIFIED, and the name is the case that matters.
 * ADR-056 stores `city`/`district` as free strings, so a client re-opening a
 * SAVED address has names and no ids — requiring an id would mean the cascade
 * could not reload the very addresses this platform wrote. Matching is
 * diacritic- and case-insensitive for the same reason (see `GeoRepository`).
 *
 * AN UNRESOLVABLE PARENT IS AN EMPTY LIST, NOT A 404. The client's saved address
 * may name a district that has since been renamed or that it spelled its own way;
 * the honest answer is "no options here", which a form renders as a free-text
 * fallback. A 404 would make it look like the endpoint was broken.
 *
 * COUNTRY-SCOPED, defaulting to the platform's home country, so this does not
 * become a TR-only route the day a second country is seeded.
 *
 * @see docs/Architecture_Decision_Record.md ADR-056
 */
final class GeoController extends BaseController
{
    public function __construct(private readonly GeoRepositoryContract $geo) {}

    /**
     * GET /api/v1/geo/provinces?country=TR
     */
    public function provinces(Request $request): JsonResponse
    {
        return $this->ok(
            GeoPlaceResource::collection($this->geo->provinces($this->country($request))),
        );
    }

    /**
     * GET /api/v1/geo/districts?province=İstanbul
     *
     * `province` accepts the NAME (what a saved address holds) or the uuid.
     */
    public function districts(Request $request): JsonResponse
    {
        $province = $this->parameter($request, 'province', 'province_id');

        if ($province === null) {
            // The parent is the whole query. Without it this would have to mean
            // "every district in the country", which is the unbounded read the
            // route shapes exist to prevent.
            return $this->ok(GeoPlaceResource::collection(collect()));
        }

        return $this->ok(GeoPlaceResource::collection(
            $this->geo->districts($province, $this->country($request)),
        ));
    }

    /**
     * GET /api/v1/geo/neighborhoods?district=Kadıköy&province=İstanbul
     *
     * `province` is optional but strongly wanted: district names repeat across
     * the country — there are dozens of "Merkez" — so without it the answer is
     * whichever one matched first.
     */
    public function neighborhoods(Request $request): JsonResponse
    {
        $district = $this->parameter($request, 'district', 'district_id');

        if ($district === null) {
            return $this->ok(GeoPlaceResource::collection(collect()));
        }

        return $this->ok(GeoPlaceResource::collection($this->geo->neighborhoods(
            $district,
            $this->parameter($request, 'province', 'province_id'),
            $this->country($request),
        )));
    }

    /**
     * A parameter under either its name form or its `*_id` alias.
     *
     * Both are accepted because the two callers differ: a form that has just
     * rendered this endpoint's own output holds a uuid, while one restoring a
     * saved address holds a name. Neither should have to convert.
     */
    private function parameter(Request $request, string $name, string $idName): ?string
    {
        foreach ([$name, $idName] as $key) {
            $value = $request->query($key);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * ISO-2, defaulting to the platform's home country.
     *
     * Length-checked rather than validated against the table: an unknown code
     * simply resolves to no provinces, which is the same empty list an
     * unresolvable parent gives, and a validation error here would tell a
     * probing client which countries the platform has seeded.
     */
    private function country(Request $request): string
    {
        $country = $request->query('country');

        if (is_string($country) && mb_strlen(trim($country)) === 2) {
            return mb_strtoupper(trim($country));
        }

        return (string) config('marketplace.localization.default_country', 'TR');
    }
}
