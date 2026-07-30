<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * Why an order stopped, and on whose say-so (§3.3).
 *
 * `cancelledBy` IS NOT DERIVED FROM THE AUTHENTICATED ACTOR, deliberately: the
 * expiry sweep has no actor at all, and it is the one cancellation a seller most
 * needs told apart from a customer changing their mind. Passing it explicitly
 * means the job and the panel say the same thing in the same field.
 *
 * The reason is optional because an expiry has none worth writing, and a
 * customer's is often "changed my mind" — but a SELLER cancelling somebody's
 * order without a reason is a support ticket waiting to happen, which is why the
 * seller surface requires it at the form rather than the DTO requiring it of
 * everyone.
 */
final class CancelOrderDTO extends BaseDTO
{
    public const string BY_CUSTOMER = 'customer';

    public const string BY_SELLER = 'seller';

    public const string BY_ADMIN = 'admin';

    public const string BY_EXPIRY = 'expiry';

    public function __construct(
        public readonly string $cancelledBy,
        public readonly ?string $reason = null,
    ) {}
}
