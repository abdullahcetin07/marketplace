<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;
use App\Modules\Loyalty\Domain\Enums\LoyaltyPointSource;

/**
 * One grant, on its way to the ledger (ADR-081).
 *
 * **THE SOURCE AND ITS UUID TRAVEL TOGETHER** because together they are the
 * idempotency key. Splitting them — a source here, an id looked up there — is how
 * a listener ends up crediting the same review twice under two spellings of the
 * same fact.
 */
final class GrantPointsDTO extends BaseDTO
{
    public function __construct(
        public readonly string $customerUuid,
        public readonly int $points,
        public readonly LoyaltyPointSource $source,
        public readonly string $sourceUuid,
        /**
         * The basket a redemption or a reversal belongs to (ADR-084). Null for the
         * three EARN sources — signing up has no checkout group.
         */
        public readonly ?string $groupUuid = null,
        /** @var array<string, mixed>|null the rate that produced it, for the audit trail */
        public readonly ?array $meta = null,
    ) {}
}
