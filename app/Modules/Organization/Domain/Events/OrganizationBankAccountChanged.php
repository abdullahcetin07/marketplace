<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A seller's payout destination moved (security audit, 2026-08-18).
 *
 * **THE IBAN IS WHERE THE PLATFORM SENDS REAL MONEY**, so changing it is the single
 * most attractive write in the seller panel. The audit found a Manager could reach
 * it by promoting itself to Finance; that route is closed, but the change itself
 * still deserves to be loud rather than silent — an operator should be able to see
 * that a payout destination moved without going looking for it.
 *
 * **IT ANNOUNCES, IT DOES NOT BLOCK.** Refusing payouts to an unverified IBAN would
 * be the stronger control and it is deliberately NOT taken here: nothing today
 * re-verifies one, so blocking would strand every seller who corrects a typo until
 * an admin re-approves their whole organization. Recorded as a follow-up rather
 * than shipped half-built.
 *
 * The previous IBAN is carried MASKED: the audit trail needs to show that the
 * destination changed, not to become a second copy of everybody's bank details.
 */
final class OrganizationBankAccountChanged extends BaseEvent
{
    public function __construct(
        public readonly int $organizationId,
        public readonly string $organizationUuid,
        public readonly ?string $previousIbanMasked,
        public readonly string $newIbanMasked,
        public readonly ?int $actorId,
    ) {
        parent::__construct();
    }
}
