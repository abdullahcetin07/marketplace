<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Listeners;

use App\Core\Domain\Contracts\ReviewQueryContract;
use App\Modules\Loyalty\Application\Actions\GrantPointsAction;
use App\Modules\Loyalty\Domain\DTOs\GrantPointsDTO;
use App\Modules\Loyalty\Domain\Enums\LoyaltyPointSource;

/**
 * A PUBLISHED review earns points, once per review (ADR-081).
 *
 * **PUBLISHED, NOT SUBMITTED**, and the difference is the whole rule. Paying on
 * submission pays for text nobody has read and rewards the person who writes forty
 * of them in an evening; paying on moderation approval (ADR-068) means the platform
 * has looked at what it is paying for.
 *
 * **THE AUTHOR COMES FROM A CORE PORT, NOT FROM THE EVENT.** `ReviewPublished`
 * carries the review, the product and the moderator — not the buyer, because
 * nothing had needed them. Loyalty may not read Reviews' table, so
 * `ReviewQueryContract` answers who wrote it. Widening the event would have been
 * the other option and a worse one: an event's payload is a promise to every
 * listener, and this listener's need is its own.
 */
final class AwardReviewPoints
{
    public function __construct(
        private readonly GrantPointsAction $grant,
        private readonly ReviewQueryContract $reviews,
    ) {}

    public function handle(object $event): void
    {
        $reviewUuid = $event->reviewUuid ?? null;

        if (! is_string($reviewUuid)) {
            return;
        }

        $customerUuid = $this->reviews->authorCustomerUuidFor($reviewUuid);

        if ($customerUuid === null) {
            return;
        }

        $this->grant->run(new GrantPointsDTO(
            customerUuid: $customerUuid,
            points: (int) settings('loyalty.earn.review', 50),
            source: LoyaltyPointSource::Review,
            sourceUuid: $reviewUuid,
            meta: ['rule' => 'review'],
        ));
    }
}
