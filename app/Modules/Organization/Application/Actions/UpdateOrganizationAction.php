<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Organization\Domain\DTOs\UpdateOrganizationDTO;
use App\Modules\Organization\Domain\Models\Organization;

/**
 * Update an organization's profile fields (name, trading name).
 *
 * Integration glue, not a new rule: it writes existing columns and the change
 * is audited by the model's Auditable trait, attributed to the acting member.
 * PATCH semantics from the DTO's `present` list.
 */
final class UpdateOrganizationAction extends BaseAction
{
    public function handle(mixed ...$arguments): Organization
    {
        /** @var Organization $organization */
        $organization = $arguments[0];
        /** @var UpdateOrganizationDTO $data */
        $data = $arguments[1];

        $changes = [];

        if ($data->has('legal_name')) {
            $changes['legal_name'] = $data->legalName;
        }

        if ($data->has('display_name')) {
            $changes['display_name'] = $data->displayName;
        }

        if ($changes !== []) {
            $organization->update($changes);
        }

        return $organization->refresh();
    }
}
