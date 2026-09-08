<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Controllers\Api\Seller;

use App\Core\Presentation\Controllers\BaseController;
use App\Core\Presentation\Support\MoneyString;
use App\Models\User;
use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Offer\Application\Import\SellerFeedIdentity;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Models\Offer;
use App\Modules\Offer\Presentation\Support\CatalogLabels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The seller's own offers, read back over the same token (ADR-076, the door's
 * missing half).
 *
 * **THE FEED COULD WRITE AND NOT READ**, which made every integration guess: a
 * seller's system knew what it had pushed and nothing about what the platform
 * actually holds — which barcodes matched, which offers are paused, what stock
 * the platform thinks is on the shelf. This answers that in the seller's own
 * vocabulary: **the row is keyed by GTIN**, the same identifier `sync` and
 * `stock` take, not by a uuid their ERP has never seen.
 *
 * **THE MERCHANT IS RESOLVED FROM THE TOKEN, NEVER FROM THE QUERY.** There is no
 * `org` parameter to tamper with and no way to widen the scope — the same
 * authorization model as the write side, and the reason this needs no policy call
 * of its own: the query cannot express somebody else's shop.
 *
 * **IT IMPORTS NO MODULE.** Titles, SKUs and barcodes come from
 * `CatalogBrowseContract` through `CatalogLabels`, primed once per page, so a
 * hundred rows cost one catalogue query rather than a hundred.
 *
 * **MONEY IS A DECIMAL STRING PAIRED WITH ITS CURRENCY** (005 §28, ADR-005).
 * `price_minor` never leaves the platform: a client that parses kuruş as a float
 * is a rounding bug waiting for a large basket.
 *
 * @see App\Modules\Offer\Presentation\Controllers\Api\Seller\OfferFeedController
 * @see docs/modules/Offer.md — the seller offer feed
 */
final class OfferListController extends BaseController
{
    public function __construct(
        private readonly SellerFeedIdentity $identity,
        private readonly CatalogLabels $labels,
    ) {}

    /**
     * GET /api/v1/seller/offers
     */
    public function index(Request $request): JsonResponse
    {
        $seller = $this->identity->forUser((int) $this->actor()->getKey());

        $query = Offer::query()
            // THE WHOLE SCOPE, AND IT IS NOT OPTIONAL. Every other filter narrows
            // within this one; nothing the caller sends can widen it.
            ->where('selling_org_id', $seller['orgId'])
            ->orderByDesc('updated_at');

        $status = $request->string('status')->toString();

        if ($status !== '' && OfferStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        // `in_stock=1` / `in_stock=0` — the question a replenishment run asks
        // before it decides what to push tonight.
        if ($request->has('in_stock')) {
            $request->boolean('in_stock')
                ? $query->where('stock_quantity', '>', 0)
                : $query->where('stock_quantity', '<=', 0);
        }

        $offers = $query->paginate($this->perPage(50, 200));

        /** @var array<int, Offer> $rows */
        $rows = $offers->items();

        $this->labels->prime(
            array_map(static fn (Offer $offer): string => $offer->product_uuid, $rows),
            array_map(static fn (Offer $offer): string => $offer->variant_uuid, $rows),
        );

        $decimals = $this->decimals($rows);

        return $this->paginated($offers, array_map(
            fn (Offer $offer): array => $this->row($offer, $decimals),
            $rows,
        ));
    }

    /**
     * @param array<int, int> $decimals minor-unit exponent, keyed by currency id
     *
     * @return array<string, mixed>
     */
    private function row(Offer $offer, array $decimals): array
    {
        $places = $decimals[$offer->currency_id] ?? 2;

        return [
            // FIRST, BECAUSE IT IS THE KEY THE CALLER WRITES WITH. A row whose
            // catalogue entry has no barcode still lists — it is the seller's
            // offer either way — and says so with null rather than being hidden.
            'gtin' => $this->labels->variantGtin($offer->variant_uuid),
            'title' => $this->labels->productTitle($offer->product_uuid),
            'brand' => $this->labels->productBrand($offer->product_uuid),
            'sku' => $this->labels->variantSku($offer->variant_uuid),
            'variant' => $this->labels->variantLabel($offer->variant_uuid),
            'price' => MoneyString::from($offer->price_minor, $places),
            'list_price' => $offer->list_price_minor === null
                ? null
                : MoneyString::from($offer->list_price_minor, $places),
            'currency' => $offer->currency->code,
            'stock' => $offer->stock_quantity,
            'status' => $offer->status->value,
            // The uuids are LAST and are the platform's identifiers, offered for
            // a caller that wants to deep-link rather than as the way in.
            'product_uuid' => $offer->product_uuid,
            'variant_uuid' => $offer->variant_uuid,
            'updated_at' => $offer->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The authenticated seller — a guard that resolved nobody is a 404, the same
     * answer the write half gives.
     */
    private function actor(): User
    {
        $actor = current_actor();

        if (! $actor instanceof User) {
            throw new NotFoundHttpException;
        }

        return $actor;
    }

    /**
     * One query for the page's currencies rather than a relation per row.
     *
     * @param array<int, Offer> $rows
     *
     * @return array<int, int>
     */
    private function decimals(array $rows): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (Offer $offer): int => (int) $offer->currency_id,
            $rows,
        )));

        if ($ids === []) {
            return [];
        }

        /** @var array<int, int> $decimals */
        $decimals = Currency::query()->whereKey($ids)->pluck('decimal_places', 'id')->all();

        return $decimals;
    }
}
