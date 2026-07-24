<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Contracts;

use App\Modules\Organization\Domain\Models\OrganizationDocument;
use Illuminate\Database\Eloquent\Collection;

/**
 * Persistence port for organization documents.
 *
 * @see App\Modules\Organization\Infrastructure\Repositories\OrganizationDocumentRepository
 */
interface OrganizationDocumentRepositoryContract
{
    public function findByUuid(string $uuid): ?OrganizationDocument;

    public function findOrFailByUuid(string $uuid): OrganizationDocument;

    /**
     * @return Collection<int, OrganizationDocument>
     */
    public function forOrganization(int $organizationId): Collection;
}
