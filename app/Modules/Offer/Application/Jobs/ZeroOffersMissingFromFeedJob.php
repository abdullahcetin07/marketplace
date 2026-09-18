<?php

declare(strict_types=1);

namespace App\Modules\Offer\Application\Jobs;

use App\Core\Application\Jobs\BaseJob;
use App\Modules\Offer\Application\Actions\UpdateOfferStockAction;
use App\Modules\Offer\Application\Import\SellerFeedIdentity;
use App\Modules\Offer\Domain\DTOs\UpdateOfferStockDTO;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Models\Offer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * "Bu liste mağazamın tamamı": whatever the file never mentioned goes to zero
 * stock (Offer.md §19).
 *
 * **IT ZEROES STOCK; IT DOES NOT WITHDRAW.** The listing, its price and its place
 * on the product page stay exactly as they were — the seller is saying "I no
 * longer hold these", not "I no longer sell these". Coming back is one number in
 * the next upload, where a withdrawal would be a new offer and a new history.
 *
 * **IT DRIVES `UpdateOfferStockAction`, ROW BY ROW** (ADR-076's load-bearing
 * rule). A mass `UPDATE offers SET stock_quantity = 0` would be right in the
 * table and invisible everywhere it matters: Inventory mirrors on-hand from the
 * offer's stock EVENT (ADR-048), the buy box reads Inventory, and search consumes
 * the same events. The slow loop is the point.
 *
 * **AN EMPTY SIGHTING LIST ABORTS.** If nothing was recorded — a file whose rows
 * all failed, a column mapped to the wrong header, a queue that never ran — then
 * "absent from the file" describes the ENTIRE shop, and the honest reading of no
 * evidence is to do nothing. This is the guard that stands between a mistyped
 * upload and an emptied storefront.
 *
 * **SCOPED TO THE ORG *AND* THE STORE THE FEED WRITES TO.** A company with two
 * storefronts uploads one list per store; the other store's offers were never
 * candidates for this file and must not be zeroed by it.
 */
final class ZeroOffersMissingFromFeedJob extends BaseJob
{
    public function __construct(private readonly int $importId)
    {
        parent::__construct();
    }

    public function handle(SellerFeedIdentity $identity, UpdateOfferStockAction $updateStock): void
    {
        $seen = DB::table('offer_feed_seen_variants')->where('import_id', $this->importId);

        if ($seen->clone()->doesntExist()) {
            return;
        }

        try {
            $import = DB::table('imports')->where('id', $this->importId)->first();

            if ($import === null) {
                return;
            }

            $seller = $identity->forUser((int) $import->user_id);
        } catch (Throwable $e) {
            report($e);

            return;
        }

        $this->missingOffers($seller['orgId'], $seller['storeUuid'])
            ->chunkById(100, function (iterable $offers) use ($updateStock): void {
                foreach ($offers as $offer) {
                    $updateStock->run($offer, new UpdateOfferStockDTO(
                        stockQuantity: 0,
                        reason: __('offer.feed.zero_missing.reason', ['import' => $this->importId]),
                    ));
                }
            });

        DB::table('offer_feed_seen_variants')->where('import_id', $this->importId)->delete();
    }

    /**
     * This shop's live offers that the file did not mention.
     *
     * **SUSPENDED AND WITHDRAWN ARE LEFT ALONE.** The stock action refuses both
     * (a suspended offer is an admin's decision, a withdrawn one is over), so
     * including them would be one exception per row for no change.
     *
     * @return Builder<Offer>
     */
    private function missingOffers(int $organizationId, string $storeUuid): Builder
    {
        /** @var Builder<Offer> $query */
        $query = Offer::query();

        return $query
            ->where('selling_org_id', $organizationId)
            ->where('store_uuid', $storeUuid)
            ->whereIn('status', [OfferStatus::Active->value, OfferStatus::Paused->value])
            ->where('stock_quantity', '>', 0)
            ->whereNotExists(function ($sub): void {
                $sub->select(DB::raw(1))
                    ->from('offer_feed_seen_variants')
                    ->whereColumn('offer_feed_seen_variants.variant_uuid', 'offers.variant_uuid')
                    ->where('offer_feed_seen_variants.import_id', $this->importId);
            });
    }
}
