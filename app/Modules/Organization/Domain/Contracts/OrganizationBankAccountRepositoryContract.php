<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Contracts;

use App\Modules\Organization\Domain\Models\OrganizationBankAccount;

/**
 * Persistence port for the payout bank account.
 *
 * @see App\Modules\Organization\Infrastructure\Repositories\OrganizationBankAccountRepository
 */
interface OrganizationBankAccountRepositoryContract
{
    /**
     * The one bank account for an organization, if set.
     */
    public function forOrganization(int $organizationId): ?OrganizationBankAccount;
}
