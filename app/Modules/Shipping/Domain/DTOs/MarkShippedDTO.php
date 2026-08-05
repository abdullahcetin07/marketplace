<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * "Kargoya verdim" — what the seller actually tells the platform (ADR-063).
 *
 * TWO FACTS AND NO DATE. `shipped_at` is `now()`, set by the action, deliberately
 * not accepted from the caller: a seller who could backdate a handover could
 * shorten the transit window that infers delivery (ADR-064), and delivery is when
 * they get paid. The one timestamp this module trusts a seller with is none.
 *
 * THE CARRIER ARRIVES AS A UUID, not an internal id (non-negotiable #7) — this
 * crosses a presentation boundary, and an internal id must never leave the
 * application to come back in.
 */
final class MarkShippedDTO extends BaseDTO
{
    public function __construct(
        public readonly string $shipmentUuid,
        public readonly string $cargoCompanyUuid,
        public readonly string $trackingNumber,
    ) {}
}
