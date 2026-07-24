<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Resources;

use App\Core\Presentation\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A document's metadata. The file itself is reached through a separate signed
 * URL (the model's temporaryUrl()), not embedded here — a private document is
 * never a plain link in a list.
 *
 * @extends BaseResource<\App\Modules\Organization\Domain\Models\OrganizationDocument>
 */
final class OrganizationDocumentResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->publicId(),
            'type' => $this->resource->type->value,
            'status' => $this->resource->status->value,
            'review_notes' => $this->resource->review_notes,
            'has_file' => $this->resource->file() !== null,
            ...$this->timestamps(),
        ];
    }
}
