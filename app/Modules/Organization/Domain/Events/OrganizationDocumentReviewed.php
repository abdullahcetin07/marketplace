<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * An admin reviewed an organization document — approved, rejected, or asked for
 * a revision.
 *
 * `decision` is the OrganizationDocumentStatus value (a string), so a consumer
 * reads it without importing the module's enum. Drives the owner notification
 * and the timeline; the decision and its notes are on the audit entry.
 */
final class OrganizationDocumentReviewed extends BaseEvent
{
    public function __construct(
        public readonly int $organizationId,
        public readonly string $documentUuid,
        public readonly string $type,
        public readonly string $decision,
    ) {
        parent::__construct();
    }
}
