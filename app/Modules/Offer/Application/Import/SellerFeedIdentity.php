<?php

declare(strict_types=1);

namespace App\Modules\Offer\Application\Import;

use App\Core\Domain\Contracts\OrganizationAuthorizationContract;
use App\Core\Domain\Contracts\StoreQueryContract;
use App\Modules\Offer\Domain\Exceptions\OfferFeedException;

/**
 * Which merchant, and which shop, is this feed writing for? (ADR-076)
 *
 * **THE ANSWER COMES FROM THE AUTHENTICATED ACTOR AND NEVER FROM THE PAYLOAD.**
 * A feed item carries a barcode, a price and a stock count — no organization, no
 * store. That is the whole authorization model: there is no field for a token to
 * lie in. Both doors (API and CSV) resolve the seller here, so neither can drift
 * into trusting its input.
 *
 * **IT IS THE PANEL'S OWN CHAIN**, not a second reading of it: memberships the
 * actor may MANAGE (`canManageOrganization` — a viewer must not push prices),
 * then that organization's LIVE stores. The seller's offer form narrows its store
 * picker exactly this way, so the two surfaces can only ever offer the same set.
 *
 * **SINGLE ACTING ORG IN v1** (spec §7). A user who manages two companies gets the
 * first, deterministically ordered, and a `store_uuid`-per-call parameter is the
 * documented way to widen this later — not something to guess at now.
 *
 * A SELLER WITH NO LIVE STORE IS REFUSED RATHER THAN DEFAULTED. Listing needs a
 * shopfront; inventing one would create offers nobody can see.
 *
 * @see App\Modules\Offer\Application\Actions\SyncSellerOfferAction
 */
final class SellerFeedIdentity
{
    public function __construct(
        private readonly OrganizationAuthorizationContract $authz,
        private readonly StoreQueryContract $stores,
    ) {}

    /**
     * @return array{orgId: int, orgUuid: string, storeUuid: string}
     */
    public function forUser(int $userId): array
    {
        foreach ($this->authz->organizationIdsForUser($userId) as $organizationId) {
            if (! $this->authz->canManageOrganization($userId, $organizationId)) {
                continue;
            }

            $stores = $this->stores->liveStoresForOrganization($organizationId);

            if ($stores === []) {
                continue;
            }

            $orgUuid = $this->authz->organizationUuidFor($organizationId);

            if ($orgUuid === null) {
                continue;
            }

            return [
                'orgId' => $organizationId,
                'orgUuid' => $orgUuid,
                // The uuid is the KEY of that map — the same shape the panel's
                // store picker renders as options.
                'storeUuid' => (string) array_key_first($stores),
            ];
        }

        throw OfferFeedException::noSellableStore();
    }
}
