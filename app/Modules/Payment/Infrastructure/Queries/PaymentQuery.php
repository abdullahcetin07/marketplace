<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Queries;

use App\Core\Domain\Contracts\PaymentQueryContract;
use App\Modules\Payment\Domain\Models\Payment;

final class PaymentQuery implements PaymentQueryContract
{
    public function redemptionDiscountFor(string $checkoutGroupUuid): int
    {
        /*
        | ONE COLUMN, ONE ROW. There is one Payment per checkout group (ADR-060),
        | so this is a lookup rather than a sum — and a group with no payment yet
        | answers zero, which is what a caller subtracting it wants.
        */
        return (int) Payment::query()
            ->where('checkout_group_uuid', $checkoutGroupUuid)
            ->value('discount_minor');
    }
}
