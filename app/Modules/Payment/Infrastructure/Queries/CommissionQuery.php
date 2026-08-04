<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Queries;

use App\Core\Domain\Contracts\CommissionQueryContract;
use App\Modules\Payment\Domain\DTOs\CommissionSubjectDTO;
use App\Modules\Payment\Domain\Services\CommissionResolver;

/**
 * Payment's implementation of the downstream commission port (ADR-061).
 *
 * A THIN ADAPTER OVER `CommissionResolver`, and deliberately nothing more. The
 * resolution rule lives in the Domain service where it can be read and tested
 * without a container; this turns the port's primitives into that service's DTO
 * and back, because Core may not name a module's types.
 *
 * ITS ONE CALLER IS ORDER, freezing the commission onto a line when a payment
 * succeeds. Payment computes; Order records — see the contract for why that way
 * round.
 *
 * @see App\Core\Domain\Contracts\CommissionQueryContract
 */
final class CommissionQuery implements CommissionQueryContract
{
    public function __construct(private readonly CommissionResolver $resolver) {}

    /**
     * @param array<int, string> $categoryPathUuids
     *
     * @return array{rule_uuid: string|null, rate: string, amount_minor: int}
     */
    public function forLine(
        string $sellerOrgUuid,
        int $baseMinor,
        ?string $productUuid = null,
        ?string $brandUuid = null,
        array $categoryPathUuids = [],
    ): array {
        $commission = $this->resolver->resolve(new CommissionSubjectDTO(
            sellerOrgUuid: $sellerOrgUuid,
            baseMinor: $baseMinor,
            productUuid: $productUuid,
            brandUuid: $brandUuid,
            categoryPathUuids: $categoryPathUuids,
        ));

        return [
            'rule_uuid' => $commission->ruleUuid,
            'rate' => $commission->rate,
            'amount_minor' => $commission->amountMinor,
        ];
    }
}
