<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure\Repositories;

use App\Modules\Organization\Domain\Contracts\OrganizationBankAccountRepositoryContract;
use App\Modules\Organization\Domain\Models\OrganizationBankAccount;

/**
 * @see App\Modules\Organization\Domain\Contracts\OrganizationBankAccountRepositoryContract
 */
final class OrganizationBankAccountRepository implements OrganizationBankAccountRepositoryContract
{
    public function forOrganization(int $organizationId): ?OrganizationBankAccount
    {
        return OrganizationBankAccount::query()->where('organization_id', $organizationId)->first();
    }
}
