<?php

declare(strict_types=1);

namespace App\Core\Domain\Contracts;

/**
 * "What does the platform take on this line?" — asked without importing Payment
 * (ADR-061, Payment.md §6).
 *
 * THE ONE CROSS-MODULE QUESTION P2 CREATES, and it points the unusual way. Payment
 * owns the commission RULES; Order owns the order LINES the answer is frozen onto.
 * Neither may import the other, so the rules answer through this port and Order
 * writes its own table.
 *
 * WHY ORDER WRITES IT RATHER THAN PAYMENT. `order_lines` is Order's aggregate, and
 * a module reaching into another's table is the boundary failing at its most
 * tempting point — the same reason Payment announces `PaymentSucceeded` instead of
 * setting an order's status (P1). Payment computes; Order records.
 *
 * IT SPEAKS IN PRIMITIVES, like every Core read port. No DTOs cross it: Core may
 * not name a module's types (`LayeringTest`), which is the rule that moved
 * `PaymentGatewayContract` into Payment during P1. Here the payload is small
 * enough that plain arrays cost nothing.
 *
 * THE INPUT IS ALL SNAPSHOT VALUES. The caller passes what was frozen on the line
 * at checkout, never a live catalogue read — so a product re-categorised next
 * month cannot change which rule applied to a sale already made.
 *
 * @see App\Modules\Payment\Infrastructure\Queries\CommissionQuery
 * @see docs/modules/Payment.md §6
 */
interface CommissionQueryContract
{
    /**
     * The commission on one line, as `['rule_uuid' => ?string, 'rate' => string,
     * 'amount_minor' => int]`.
     *
     * `rate` is a decimal-string RATIO ("0.1500" = 15%) and `amount_minor` is
     * integer kuruş — the money rule in one return type (ADR-005).
     *
     * A platform with no rules configured yields a zero rate and zero kuruş rather
     * than an error: taking no commission is a valid state, and one the module has
     * to survive on day one.
     *
     * @param array<int, string> $categoryPathUuids root first, including the line's own category, so a rule on an ancestor matches
     *
     * @return array{rule_uuid: string|null, rate: string, amount_minor: int}
     */
    public function forLine(
        string $sellerOrgUuid,
        int $baseMinor,
        ?string $productUuid = null,
        ?string $brandUuid = null,
        array $categoryPathUuids = [],
    ): array;
}
