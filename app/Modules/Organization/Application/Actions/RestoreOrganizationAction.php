<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Context\AuditContext;
use App\Models\User;
use App\Modules\Organization\Domain\Enums\OrganizationStatus;
use App\Modules\Organization\Domain\Events\OrganizationRestored;
use App\Modules\Organization\Domain\Models\Organization;

/**
 * An admin restores a suspended organization to operation.
 *
 * The mirror of suspend: it returns the company to Approved and clears the
 * suspension stamp. Audited with reason; announced for the timeline.
 */
final class RestoreOrganizationAction extends BaseAction
{
    public function handle(mixed ...$arguments): Organization
    {
        /** @var Organization $organization */
        $organization = $arguments[0];
        /** @var User $actor */
        $actor = $arguments[1];
        $reason = $arguments[2] ?? null;

        AuditContext::withReasonFor($reason, function () use ($organization): void {
            $organization->forceFill([
                'status' => OrganizationStatus::Approved,
                'suspended_at' => null,
                'suspended_by' => null,
            ])->save();
        });

        return $organization;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Organization $result */
        OrganizationRestored::dispatch($result->getKey(), $result->uuid, $result->owner_id);
    }
}
