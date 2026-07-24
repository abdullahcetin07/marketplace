<?php

declare(strict_types=1);

namespace App\Modules\Localization\Presentation\Resources;

use App\Core\Presentation\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A timezone, as the public API exposes it.
 *
 * `offset` is recomputed live via `offsetLabel()`, never read from the stored
 * `offset_minutes` column — that column is a sorting convenience and is wrong
 * for half the year in any DST zone.
 *
 * @extends BaseResource<\App\Modules\Localization\Domain\Models\Timezone>
 */
final class TimezoneResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->publicId(),
            'name' => $this->resource->name,
            'label' => $this->resource->label,
            'offset' => $this->resource->offsetLabel(),
        ];
    }
}
