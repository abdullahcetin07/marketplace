<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Policies;

use App\Models\User;
use App\Modules\Organization\Domain\Contracts\OrganizationMemberRepositoryContract;
use App\Modules\Organization\Domain\Models\OrganizationDocument;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * Authorises access to an organization's documents.
 *
 * Two audiences again:
 *  - **view** — an active member of the document's organization (the file is a
 *    signed URL even then; a non-member sees nothing, ADR-030).
 *  - **review** — a platform admin holding `organization.review_documents`. This
 *    IS a Spatie permission (a cross-org admin power), not an org capability.
 *
 * @see docs/modules/Organization.md §4.2, §9
 */
final class OrganizationDocumentPolicy
{
    use HandlesAuthorization;

    public function __construct(
        private readonly OrganizationMemberRepositoryContract $members,
    ) {}

    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function view(User $user, OrganizationDocument $document): Response
    {
        return $this->members->isActiveMember($document->organization_id, $user->getKey())
            ? Response::allow()
            : Response::deny(__('errors.forbidden'));
    }

    public function review(User $user, OrganizationDocument $document): Response
    {
        return $user->hasPermissionTo('organization.review_documents', $user->guardName())
            ? Response::allow()
            : Response::deny(__('errors.forbidden'));
    }
}
