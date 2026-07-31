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
 *
 * `zeroSellerStock` IS AN INTENT ABOUT THE SELLER'S REAL SHELF, not about this
 * order (ADR-057). A merchant cancelling because they cannot fulfil is telling
 * the platform something it did not know: they do not have the goods. Releasing
 * this order's hold would put the units straight back on sale and the next buyer
 * would hit the same wall — so the seller path also ZEROES their declared stock
 * for that variant, and sales stop until they re-declare. Anti-oversell.
 *
 * IT IS A FLAG RATHER THAN AN INFERENCE FROM `cancelledBy`, because the ADMIN
 * path needs both: an admin cancelling a dispute releases and nothing more, while
 * an admin cancelling a seller-fault case zeroes exactly as the seller would. The
 * actor says who; this says what it means about their stock.
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
        public readonly bool $zeroSellerStock = false,
    ) {}

    /**
     * Whether this cancellation says the seller has no stock (ADR-057).
     *
     * ALWAYS TRUE FOR THE SELLER PATH, whatever the caller passed: a merchant
     * cancelling because they cannot fulfil has told us they have none, and a
     * surface that forgot the flag must not turn that into a silent re-listing of
     * goods that do not exist.
     */
    public function zeroesSellerStock(): bool
    {
        return $this->zeroSellerStock || $this->cancelledBy === self::BY_SELLER;
    }
}
