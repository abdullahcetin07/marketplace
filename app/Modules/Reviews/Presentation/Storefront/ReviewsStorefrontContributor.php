<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Presentation\Storefront;

use App\Core\Domain\Contracts\OrganizationAuthorizationContract;
use App\Core\Domain\Contracts\ReviewQueryContract;
use App\Core\Domain\Storefront\StorefrontContext;
use App\Core\Domain\Storefront\StorefrontContributorContract;

/**
 * A shop's rating, on its own store page (SEO audit #4, ADR-036/066).
 *
 * **THROUGH THE COMPOSITION SEAM, BECAUSE STORE IS FROZEN.** Store composes the
 * page and other modules contribute to it (ADR-036); a rating is Reviews' fact, so
 * Reviews contributes it rather than Store growing a read into somebody else's
 * table. This is the second module to use that seam after Offer's product listing.
 *
 * **THE REVIEWS ARE THE PRODUCT'S, GROUPED BY WHO SOLD THE THING.** A review
 * carries the seller it was bought from as a tag copied from the delivered order
 * line, never chosen by the buyer (ADR-066) — so this rollup is the same reviews
 * seen from the shop's side, not a separate opinion nobody was asked for. There is
 * still no way to review a SELLER, and this does not create one.
 *
 * **A SHOP WITH NO REVIEWS ANSWERS `value: null`, NEVER A ZERO.** The storefront
 * renders stars and emits `aggregateRating` in JSON-LD from this; structured data
 * that invents a rating is exactly what search engines penalise, and "0.0 out of 5"
 * is a claim about a shop nobody has judged.
 *
 * An explicit null rather than an absent key, because the composition registry
 * includes every contributor's section whether or not it has content (Offer's
 * empty listing does the same) — so `null` is the only way to say "no rating" in a
 * shape a client can branch on.
 */
final class ReviewsStorefrontContributor implements StorefrontContributorContract
{
    public function __construct(
        private readonly ReviewQueryContract $reviews,
        private readonly OrganizationAuthorizationContract $authz,
    ) {}

    public function key(): string
    {
        return 'rating';
    }

    /**
     * @return array<string, mixed>
     */
    public function contribute(StorefrontContext $context): array
    {
        /*
        | THE REVIEWS ARE TAGGED WITH THE SELLING ORG'S UUID; the page carries its
        | internal id. One Core read bridges them — Reviews imports no module.
        */
        $orgUuid = $this->authz->organizationUuidFor($context->organizationId);

        $rating = $orgUuid === null ? null : $this->reviews->sellerRatingFor($orgUuid);

        return [
            'value' => $rating['rating'] ?? null,
            'count' => $rating['count'] ?? 0,
        ];
    }
}
