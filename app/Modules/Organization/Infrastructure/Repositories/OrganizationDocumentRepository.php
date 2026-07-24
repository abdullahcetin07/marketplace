<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure\Repositories;

use App\Modules\Organization\Domain\Contracts\OrganizationDocumentRepositoryContract;
use App\Modules\Organization\Domain\Models\OrganizationDocument;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * @see App\Modules\Organization\Domain\Contracts\OrganizationDocumentRepositoryContract
 */
final class OrganizationDocumentRepository implements OrganizationDocumentRepositoryContract
{
    public function findByUuid(string $uuid): ?OrganizationDocument
    {
        return OrganizationDocument::query()->with('media')->where('uuid', $uuid)->first();
    }

    public function findOrFailByUuid(string $uuid): OrganizationDocument
    {
        $document = $this->findByUuid($uuid);

        if ($document === null) {
            throw (new ModelNotFoundException)->setModel(OrganizationDocument::class, [$uuid]);
        }

        return $document;
    }

    /**
     * @return Collection<int, OrganizationDocument>
     */
    public function forOrganization(int $organizationId): Collection
    {
        return OrganizationDocument::query()
            ->with('media')
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->get();
    }
}
