<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Organization\Domain\Models\OrganizationInvitation;

/**
 * The recipient declines an invitation.
 *
 * Authorised (email match) by the policy; this just records the decision. A
 * rejected invitation is terminal and cannot be accepted afterwards.
 */
final class RejectInvitationAction extends BaseAction
{
    public function handle(mixed ...$arguments): mixed
    {
        /** @var OrganizationInvitation $invitation */
        $invitation = $arguments[0];

        $invitation->markRejected();

        return null;
    }
}
