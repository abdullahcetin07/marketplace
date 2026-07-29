<?php

declare(strict_types=1);

namespace App\Modules\Offer\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Context\AuditContext;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Events\OfferPaused;
use App\Modules\Offer\Domain\Exceptions\OfferException;
use App\Modules\Offer\Domain\Models\Offer;

/**
 * The seller takes an offer out of the buy box without destroying it (§3.1).
 *
 * WHY THIS EXISTS ALONGSIDE ZERO STOCK. They look alike from a buyer's side and
 * are not the same statement: "I have none right now" is stock, "I have stopped
 * selling this" is a pause. Collapsing them would make a seller drop their price
 * history and their place in the buy box just to stop selling for a week.
 *
 * `byCascade` is threaded through so the same action serves the seller's own
 * pause and the product-lifecycle cascade (§3.5) — the flag is what later lets a
 * re-publish reactivate exactly the offers ITS archiving paused, and leave the
 * seller's own decision alone.
 */
final class PauseOfferAction extends BaseAction
{
    public function handle(mixed ...$arguments): Offer
    {
        /** @var Offer $offer */
        $offer = $arguments[0];
        $reason = $arguments[1] ?? null;
        $byCascade = (bool) ($arguments[2] ?? false);

        // A cascade may pause an active offer; a seller may not pause what an
        // admin suspended, nor what they already withdrew.
        if (! $offer->status->canSellerTransitionTo(OfferStatus::Paused)) {
            throw OfferException::invalidTransition($offer->status, OfferStatus::Paused);
        }

        AuditContext::withReasonFor($reason, function () use ($offer, $byCascade): void {
            $offer->forceFill([
                'status' => OfferStatus::Paused,
                'paused_by_cascade' => $byCascade,
            ])->save();
        });

        return $offer;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Offer $result */
        OfferPaused::dispatch(
            $result->getKey(),
            $result->uuid,
            $result->variant_uuid,
            $result->product_uuid,
            $result->paused_by_cascade,
        );
    }
}
