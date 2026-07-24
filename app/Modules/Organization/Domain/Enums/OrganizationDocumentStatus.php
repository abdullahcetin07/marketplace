<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * The review state of an uploaded document.
 *
 * Each document is reviewed individually; the organization as a whole is
 * approved separately (§4.2).
 *
 * REVISION-FRIENDLY: `NeedsRevision` is the humane default for a fixable problem
 * (a blurry scan, a wrong page) — it asks the seller to re-upload rather than
 * terminally rejecting. `Rejected` remains for a document that cannot be
 * accepted at all (a forgery, the wrong company).
 *
 * @see App\Modules\Organization\Domain\Models\OrganizationDocument
 */
enum OrganizationDocumentStatus: string
{
    use HasEnumHelpers;

    case Pending = 'pending';
    case Approved = 'approved';
    case NeedsRevision = 'needs_revision';
    case Rejected = 'rejected';

    /**
     * The states an admin's review may set (i.e. not the initial Pending).
     *
     * @return array<int, self>
     */
    public static function reviewOutcomes(): array
    {
        return [self::Approved, self::NeedsRevision, self::Rejected];
    }
}
