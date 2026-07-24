<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Context\AuditContext;
use App\Modules\Organization\Domain\Models\Organization;

/**
 * An admin sets an organization's store-limit override and/or plan.
 *
 * Integration glue over existing columns — it does not change how the limit
 * RESOLVES (override → plan → config, ADR-028), only the inputs. An
 * administrative action, so it is audited with the admin's reason. `override`
 * null clears the bespoke grant, falling back to the plan/config.
 */
final class SetStoreLimitAction extends BaseAction
{
    public function handle(mixed ...$arguments): Organization
    {
        /** @var Organization $organization */
        $organization = $arguments[0];
        /** @var int|null $override */
        $override = $arguments[1];
        /** @var int|null $planId */
        $planId = $arguments[2];
        $reason = $arguments[3] ?? null;

        AuditContext::withReasonFor($reason, function () use ($organization, $override, $planId): void {
            $organization->forceFill([
                'store_limit_override' => $override,
                'plan_id' => $planId,
            ])->save();
        });

        return $organization->refresh();
    }
}
