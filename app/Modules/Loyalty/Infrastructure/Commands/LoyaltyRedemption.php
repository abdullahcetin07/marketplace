<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Infrastructure\Commands;

use App\Core\Domain\Contracts\LoyaltyContract;
use App\Modules\Loyalty\Application\Actions\GrantPointsAction;
use App\Modules\Loyalty\Domain\Contracts\LoyaltyLedgerRepositoryContract;
use App\Modules\Loyalty\Domain\DTOs\GrantPointsDTO;
use App\Modules\Loyalty\Domain\Enums\LoyaltyPointSource;
use App\Modules\Loyalty\Domain\Models\LoyaltyHold;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Loyalty's answer to the Core redemption port (ADR-084).
 *
 * **THE SPENDABLE BALANCE IS THE LEDGER SUM MINUS WHAT IS HELD**, and neither
 * number is stored: the sum is computed on read (ADR-081) and the holds are a table
 * anybody can count. A "spendable" column would be a third source of truth and the
 * first one to drift.
 *
 * **THE HOLD IS WHAT MAKES TWO OPEN TABS SAFE.** A customer with 500 points must not
 * commit 500 in each; the second hold sees the first one's claim because
 * `spendable()` subtracts every OTHER group's hold, and the write sits inside a
 * transaction so the two cannot pass each other.
 *
 * **COMMIT IS THE ONLY METHOD THAT TOUCHES THE LEDGER.** Hold and release move a row
 * in `loyalty_holds`; reverse adds a positive row. Nothing here updates or deletes a
 * ledger entry, because nothing can (non-negotiable #9).
 *
 * @see App\Core\Domain\Contracts\LoyaltyContract
 */
final class LoyaltyRedemption implements LoyaltyContract
{
    public function __construct(
        private readonly LoyaltyLedgerRepositoryContract $ledger,
        private readonly GrantPointsAction $grant,
    ) {}

    /**
     * @return array{points_applied: int, discount_minor: int, payable_minor: int, max_points: int}
     */
    public function quote(
        string $customerUuid,
        int $cartTotalMinor,
        ?int $requestedPoints = null,
        ?string $checkoutGroupUuid = null,
    ): array {
        $cartTotalMinor = max(0, $cartTotalMinor);

        if (! settings('loyalty.enabled', true)) {
            /*
            | SWITCHED OFF MEANS "NOTHING TO OFFER", NOT AN ERROR. The checkout page
            | asks this on every render; a refusal would be a broken page rather
            | than a hidden control.
            */
            return $this->answer(0, 0, $cartTotalMinor, 0);
        }

        $spendable = $this->spendable($customerUuid, exceptGroup: $checkoutGroupUuid);

        /*
        | **CLAMPED TO THE CART AS WELL AS TO THE BALANCE**, because change is not
        | given in cash: spending 900 points on a 5 TL basket would either overpay
        | or need a refund path for points, and neither is a thing the platform
        | offers.
        */
        $maxPoints = min($spendable, $this->pointsWorth($cartTotalMinor));

        $applied = $requestedPoints === null ? $maxPoints : max(0, min($requestedPoints, $maxPoints));

        $discount = $this->minorValueOf($applied);

        return $this->answer($applied, $discount, max(0, $cartTotalMinor - $discount), $maxPoints);
    }

    public function hold(string $customerUuid, int $points, string $checkoutGroupUuid): int
    {
        if (! settings('loyalty.enabled', true) || $points <= 0) {
            $this->release($checkoutGroupUuid);

            return 0;
        }

        return DB::transaction(function () use ($customerUuid, $points, $checkoutGroupUuid): int {
            /*
            | **THE EXISTING HOLD IS EXCLUDED FROM THE BALANCE IT IS CHECKED
            | AGAINST.** A shopper who held 100 and comes back to hold 120 must be
            | measured against a balance that does not already count their own 100,
            | or the second attempt fails against a claim that is about to be
            | replaced.
            */
            $spendable = $this->spendable($customerUuid, exceptGroup: $checkoutGroupUuid);

            $held = max(0, min($points, $spendable));

            if ($held === 0) {
                $this->release($checkoutGroupUuid);

                return 0;
            }

            LoyaltyHold::query()->updateOrCreate(
                ['checkout_group_uuid' => $checkoutGroupUuid],
                ['customer_uuid' => $customerUuid, 'points' => $held],
            );

            return $held;
        });
    }

    public function commit(string $checkoutGroupUuid): int
    {
        $hold = LoyaltyHold::query()->where('checkout_group_uuid', $checkoutGroupUuid)->first();

        if ($hold === null) {
            /*
            | NOTHING HELD IS A NO-OP, NOT A FAILURE. The callback is retried
            | (ADR-060), so the second delivery of the same success finds the hold
            | already consumed — and a payment with no points takes this path every
            | time.
            */
            return 0;
        }

        $points = $hold->points;

        $entry = $this->grant->run(new GrantPointsDTO(
            customerUuid: $hold->customer_uuid,
            // NEGATIVE: a spend is a row, never a deletion (ADR-081).
            points: -$points,
            source: LoyaltyPointSource::Redemption,
            sourceUuid: $checkoutGroupUuid,
            groupUuid: $checkoutGroupUuid,
            meta: ['rule' => 'redemption', 'checkout_group_uuid' => $checkoutGroupUuid],
        ));

        // The hold has done its job either way — including when the ledger's unique
        // key rejected a duplicate commit, which means it was already spent.
        $hold->delete();

        return $entry === null ? 0 : $points;
    }

    public function release(string $checkoutGroupUuid): void
    {
        LoyaltyHold::query()->where('checkout_group_uuid', $checkoutGroupUuid)->delete();
    }

    public function reverse(
        string $checkoutGroupUuid,
        string $cause,
        float $cumulativeFraction,
        string $refundUuid,
    ): int {
        $committed = $this->ledger->entryFor(LoyaltyPointSource::Redemption, $checkoutGroupUuid);

        if ($committed === null) {
            return 0;
        }

        $spent = abs($committed->points);

        /*
        | **THE TARGET IS WHERE THE BASKET SHOULD END UP; THE CREDIT IS THE DELTA.**
        | Floored, and clamped so a fraction drifting over 1.0 through floating
        | error cannot hand back more than was spent. N partial refunds then sum to
        | exactly `$spent`, in any order and at any granularity — which is what
        | keying per refund without the delta got wrong in the opposite direction.
        */
        $target = (int) floor($spent * max(0.0, min(1.0, $cumulativeFraction)));

        $alreadyBack = $this->ledger->reversedPointsFor($checkoutGroupUuid);

        $points = $target - $alreadyBack;

        if ($points <= 0) {
            // Already whole — a retried event, or a refund that rounds to nothing.
            return 0;
        }

        $entry = $this->grant->run(new GrantPointsDTO(
            customerUuid: $committed->customer_uuid,
            points: $points,
            source: LoyaltyPointSource::Reversal,
            /*
            | KEYED ON THE REFUND ITSELF, so two partial returns of one order are two
            | rows rather than one silently dropped, and a retried refund event is
            | still one credit.
            */
            sourceUuid: $refundUuid,
            groupUuid: $checkoutGroupUuid,
            meta: [
                'rule' => 'reversal',
                'cause' => $cause,
                'cumulative_fraction' => $cumulativeFraction,
                'committed' => $spent,
                'already_reversed' => $alreadyBack,
            ],
        ));

        return $entry === null ? 0 : $points;
    }

    /**
     * What this customer may actually spend right now.
     */
    private function spendable(string $customerUuid, ?string $exceptGroup = null): int
    {
        $balance = $this->ledger->balanceFor($customerUuid);

        $held = (int) LoyaltyHold::query()
            ->where('customer_uuid', $customerUuid)
            ->when(
                $exceptGroup !== null,
                static fn (Builder $query): Builder => $query->where('checkout_group_uuid', '!=', $exceptGroup),
            )
            ->sum('points');

        return max(0, $balance - $held);
    }

    /**
     * How many points it takes to cover this much money.
     */
    private function pointsWorth(int $minor): int
    {
        $perPoint = $this->perPointMinor();

        return $perPoint <= 0 ? 0 : (int) floor($minor / $perPoint);
    }

    private function minorValueOf(int $points): int
    {
        return $points * $this->perPointMinor();
    }

    /**
     * One point, in kuruş.
     *
     * **THE SETTING IS A DECIMAL STRING AND BECOMES AN INTEGER HERE, ONCE.** Money
     * is minor units server-side (ADR-005); converting at every call site is how one
     * of them ends up multiplying a balance by a float.
     */
    private function perPointMinor(): int
    {
        return (int) round(((float) settings('loyalty.redeem.value', '0.05')) * 100);
    }

    /**
     * @return array{points_applied: int, discount_minor: int, payable_minor: int, max_points: int}
     */
    private function answer(int $applied, int $discount, int $payable, int $maxPoints): array
    {
        return [
            'points_applied' => $applied,
            'discount_minor' => $discount,
            'payable_minor' => $payable,
            'max_points' => $maxPoints,
        ];
    }
}
