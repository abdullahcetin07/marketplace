<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Context\AuditContext;
use App\Models\User;
use App\Modules\Organization\Domain\Enums\OrganizationDocumentStatus;
use App\Modules\Organization\Domain\Events\OrganizationDocumentReviewed;
use App\Modules\Organization\Domain\Models\OrganizationDocument;

/**
 * An admin reviews an uploaded document — approve, ask for a revision, or reject.
 *
 * REVISION-FRIENDLY: the decision is a status (`Approved`, `NeedsRevision`,
 * `Rejected`), not a yes/no, so a fixable problem sends the document back
 * instead of terminally rejecting it. Passing the initial `Pending` state is
 * refused — a review is a decision.
 *
 * AN ADMINISTRATIVE ACTION — audited. The status change is a model diff and the
 * reviewer's notes ride the audit context as the reason.
 */
final class ReviewDocumentAction extends BaseAction
{
    private OrganizationDocumentStatus $decision;

    public function handle(mixed ...$arguments): OrganizationDocument
    {
        /** @var OrganizationDocument $document */
        $document = $arguments[0];
        /** @var OrganizationDocumentStatus $decision */
        $decision = $arguments[1];
        $notes = $arguments[2] ?? null;
        /** @var User $reviewer */
        $reviewer = $arguments[3];

        if (! in_array($decision, OrganizationDocumentStatus::reviewOutcomes(), true)) {
            throw new \InvalidArgumentException('A document review must approve, request a revision, or reject.');
        }

        $this->decision = $decision;

        AuditContext::withReasonFor($notes, function () use ($document, $decision, $notes, $reviewer): void {
            $document->forceFill([
                'status' => $decision,
                'reviewed_by' => $reviewer->getKey(),
                'review_notes' => $notes,
            ])->save();
        });

        return $document;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var OrganizationDocument $result */
        OrganizationDocumentReviewed::dispatch(
            $result->organization_id,
            $result->uuid,
            $result->type->value,
            $this->decision->value,
        );
    }
}
