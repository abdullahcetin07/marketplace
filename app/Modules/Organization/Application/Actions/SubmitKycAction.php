<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Organization\Domain\DTOs\SubmitKycDTO;
use App\Modules\Organization\Domain\Models\OrganizationKyc;

/**
 * Submit or update an organization's KYC data.
 *
 * One record per organization (upsert on `organization_id`). `submitted_at` is
 * stamped so an admin knows the company has presented its details for review —
 * the org itself is approved separately, as a whole (§4.2). The national id is
 * encrypted on the model and excluded from audit; the rest of the change is
 * audited, attributed to the acting member.
 */
final class SubmitKycAction extends BaseAction
{
    public function handle(mixed ...$arguments): OrganizationKyc
    {
        /** @var SubmitKycDTO $data */
        $data = $arguments[0];

        $kyc = OrganizationKyc::query()->firstOrNew([
            'organization_id' => $data->organizationId,
        ]);

        $kyc->forceFill([
            'organization_id' => $data->organizationId,
            'tax_number' => $data->taxNumber,
            'registration_number' => $data->registrationNumber,
            'authorized_person_name' => $data->authorizedPersonName,
            'authorized_person_national_id' => $data->authorizedPersonNationalId,
            'metadata' => $data->metadata === [] ? null : $data->metadata,
            'submitted_at' => now(),
        ])->save();

        return $kyc;
    }
}
