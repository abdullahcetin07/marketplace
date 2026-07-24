<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Resources;

use App\Core\Presentation\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A bank account — masked IBAN only, never the full number.
 *
 * @extends BaseResource<\App\Modules\Organization\Domain\Models\OrganizationBankAccount>
 */
final class OrganizationBankAccountResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->publicId(),
            'label' => $this->resource->label,
            'is_primary' => $this->resource->is_primary,
            'account_holder' => $this->resource->account_holder,
            // Last four digits only — the full IBAN never leaves the API.
            'iban' => $this->resource->maskedIban(),
            'bank_name' => $this->resource->bank_name,
            'currency' => $this->whenLoaded('currency', fn (): ?string => $this->resource->currency?->code),
            'verified' => $this->resource->verified_at !== null,
            ...$this->timestamps(),
        ];
    }
}
