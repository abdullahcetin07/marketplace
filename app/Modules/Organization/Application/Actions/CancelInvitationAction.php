<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Organization\Domain\Models\OrganizationInvitation;

/**
 * The issuer withdraws a pending invitation.
 *
 * Authorised by the `invitation.manage` capability (the policy); this records
 * the withdrawal. The token is now dead — its hash remains only as a terminal,
 * unusable row.
 */
final class CancelInvitationAction extends BaseAction
{
    public function handle(mixed ...$arguments): void
    {
        /** @var OrganizationInvitation $invitation */
        $invitation = $arguments[0];

        $invitation->markCancelled();
    }
}
