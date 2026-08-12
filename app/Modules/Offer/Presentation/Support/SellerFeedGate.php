<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Support;

use App\Models\User;
use App\Modules\Offer\Domain\Models\Offer;
use Illuminate\Support\Facades\Gate;

/**
 * The feed asks `OfferPolicy` the same question the panel asks (ADR-076).
 *
 * **THE FEED USED TO ANSWER IT ITSELF, AND THAT WAS THE DEVIATION.**
 * `SellerFeedIdentity` resolves the acting merchant by walking the memberships
 * the actor may MANAGE — which is, predicate for predicate, what
 * `OfferPolicy::createFor()` decides. Two copies of one authorization rule agree
 * right up until somebody tightens one of them: a policy that later refuses a
 * suspended organization, or demands an org role, would go on being ignored by
 * the two doors that write the most offers. The work order said reuse the
 * policy, not re-implement it.
 *
 * **ONE CHECK PER CALL, NOT PER ITEM.** `createFor` is asked about the acting
 * organization, and every offer either door then touches is looked up scoped to
 * that same organization — so `OfferPolicy::manage()`, which is
 * `canManageOrganization($user, $offer->selling_org_id)`, is the identical
 * question about the identical org. Asking it four thousand more times would
 * cost four thousand membership reads to reach the same answer. If `manage()`
 * ever grows a condition on the OFFER rather than its owner, this is the comment
 * that has gone stale and the check must move into the loop.
 *
 * **IT THROWS RATHER THAN RETURNS.** A refusal here is not a bad item to report
 * beside the good ones — it is the whole call being answered by somebody who may
 * not write offers, so it is a 403 for the request rather than a line in a tally.
 *
 * @see App\Modules\Offer\Presentation\Policies\OfferPolicy
 * @see App\Modules\Offer\Application\Import\SellerFeedIdentity
 */
final class SellerFeedGate
{
    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function assertMayWriteFor(User $actor, int $organizationId): void
    {
        Gate::forUser($actor)->authorize('createFor', [Offer::class, $organizationId]);
    }
}
