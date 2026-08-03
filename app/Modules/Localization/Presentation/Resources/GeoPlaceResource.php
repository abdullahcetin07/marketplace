<?php

declare(strict_types=1);

namespace App\Modules\Localization\Presentation\Resources;

use App\Core\Presentation\Resources\BaseResource;
use App\Modules\Localization\Domain\Models\GeoProvince;
use Illuminate\Http\Request;

/**
 * One place in the geography cascade — a province, a district or a neighbourhood.
 *
 * ONE RESOURCE FOR ALL THREE LEVELS, because all three answer the same question
 * for a client: what do I put in this `<option>`, and what do I send back. Three
 * near-identical classes would be three places to forget the same rule.
 *
 * `id` IS THE UUID (non-negotiable #7) — but it is NOT what a client sends when
 * saving an address. ADR-056 keeps `city`/`district`/`neighborhood` free strings,
 * so the NAME is the value and the uuid is a stable handle for re-opening the
 * cascade or for a future client that would rather not round-trip a name. Both
 * are accepted by the endpoints that take a parent.
 *
 * `code` APPEARS ONLY ON A PROVINCE, and only when it has one — the TR plate code
 * (01–81). Rendering `"code": null` on all 73,300 neighbourhoods would be a
 * column of nulls on the platform's largest payload.
 *
 * @extends BaseResource<GeoProvince|\App\Modules\Localization\Domain\Models\GeoDistrict|\App\Modules\Localization\Domain\Models\GeoNeighborhood>
 */
final class GeoPlaceResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = [
            'id' => $this->publicId(),
            'name' => $this->resource->name,
        ];

        if ($this->resource instanceof GeoProvince && $this->resource->code !== null) {
            $payload['code'] = $this->resource->code;
        }

        return $payload;
    }
}
