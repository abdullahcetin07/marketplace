<?php

declare(strict_types=1);

namespace App\Core\Domain\Contracts;

/**
 * What another module may ask Payment about a settled basket (ADR-084).
 *
 * **ADDED SO POINTS DO NOT REGENERATE ON MONEY PAID WITH POINTS.** The purchase
 * sweep earns on what a customer actually spent (ADR-082 §2.3), and the discount
 * their points bought is Payment's fact: it lives on the payment and nowhere else,
 * because the platform funds it and no seller-order, commission or KDV moves.
 *
 * **IT ANSWERS ONE NUMBER PER BASKET, NOT A PAYMENT.** Order needs the discount to
 * subtract; handing back a Payment model would drag settlement, refunds and PSP
 * references into a module that must not know what a card is.
 */
interface PaymentQueryContract
{
    /**
     * The points-funded discount applied to this checkout group, in minor units.
     *
     * Zero when nothing was redeemed, when no payment exists yet, or when the
     * programme is off — all of which mean the same thing to a caller subtracting
     * it.
     */
    public function redemptionDiscountFor(string $checkoutGroupUuid): int;
}
