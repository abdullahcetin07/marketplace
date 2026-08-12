<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Filament\Seller\Imports;

use App\Core\Domain\Contracts\CatalogQueryContract;
use App\Models\User;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Offer\Application\Actions\SyncSellerOfferAction;
use App\Modules\Offer\Application\Import\SellerFeedIdentity;
use App\Modules\Offer\Domain\Contracts\OfferRepositoryContract;
use App\Modules\Offer\Domain\DTOs\SyncOfferDTO;
use App\Modules\Offer\Domain\Exceptions\OfferFeedException;
use App\Modules\Offer\Domain\Models\Offer;
use App\Modules\Offer\Presentation\Support\SellerFeedGate;
use Carbon\CarbonInterface;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

/**
 * The seller's spreadsheet door onto the offer feed (ADR-076, door two).
 *
 * **THE SAME BRAIN AS THE API.** Every row becomes a `SyncOfferDTO` and goes
 * through `SyncSellerOfferAction` — the class holds no feed logic, because two
 * copies of "what does a row mean" is how a CSV and an API start disagreeing about
 * the same seller's catalogue.
 *
 * **THE THREE OVERRIDES ARE THE INTEGRATION**, exactly as in the catalogue
 * importer (ADR-074): Filament maps one row to one model, and a feed row is an
 * upsert driven through other actions. `resolveRecord()` does the work and returns
 * the offer; `fillRecord()` and `saveRecord()` do nothing, because `fiyat` is not a
 * column on `offers` and the actions have already committed.
 *
 * **A REJECTED ROW IS TRANSLATED, WHICH IS THE ADR-075 LESSON PAID FOR ONCE
 * ALREADY.** `ImportCsv` records a `RowImportFailedException` with its message and
 * carries on; any other Throwable it logs WITHOUT a message, collects, and rethrows
 * — failing the job, which the queue then retries, re-running the whole chunk. That
 * is how five bad catalogue rows became 29,074 attempts overnight. A feed row that
 * names an unknown barcode is ordinary, so it must never be able to do that.
 *
 * **THE MERCHANT COMES FROM THE UPLOADER, NOT THE FILE.** There is no seller column
 * and there will not be one: a spreadsheet naming an organization is a spreadsheet
 * that can name somebody else's.
 *
 * @see App\Modules\Offer\Presentation\Controllers\Api\Seller\OfferFeedController
 * @see docs/modules/Offer.md — the seller offer feed
 */
final class OfferImporter extends Importer
{
    protected static ?string $model = Offer::class;

    /**
     * @return array<int, ImportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ImportColumn::make('gtin')
                ->label(__('offer.feed.column.gtin'))
                ->requiredMapping()
                ->example('8690000000001')
                ->rules(['required', 'string', 'max:14']),

            /*
            | A STRING, NOT `numeric`. The spreadsheet writes "129,90" and the
            | conversion to kuruş happens once, below — a float in between is the
            | financial bug ADR-005 exists to prevent.
            |
            | **AND THE SHAPE IS VALIDATED HERE BECAUSE `toMinor()` NEVER REFUSES.**
            | It coerces: `12.5.6` becomes 12,50 TL, `1e3` becomes 1.000,00 and
            | `abc` becomes zero. Without this rule a mistyped cell does not fail
            | the row — it quietly becomes a real price on a real offer, which is
            | the worst of the three outcomes. Same expression as the API door.
            |
            | More than two decimals is allowed and rounded to the kuruş: a stock
            | system deriving prices from a KDV-exclusive base emits `494.244`,
            | and refusing that would reject most of a legitimate export.
            */
            ImportColumn::make('fiyat')
                ->label(__('offer.feed.column.price'))
                ->requiredMapping()
                ->example('129,90')
                ->rules(['required', 'string', 'max:16', 'regex:/^\d+([.,]\d+)?$/']),

            ImportColumn::make('stok')
                ->label(__('offer.feed.column.stock'))
                ->requiredMapping()
                ->example('12')
                ->rules(['required', 'integer', 'min:0']),

            ImportColumn::make('liste_fiyati')
                ->label(__('offer.feed.column.list_price'))
                ->example('159,90')
                ->rules(['nullable', 'string', 'max:16', 'regex:/^\d+([.,]\d+)?$/']),
        ];
    }

    /**
     * The row, applied. @see the class note on why this method does the work.
     */
    public function resolveRecord(): Model
    {
        /** @var User $importer */
        $importer = $this->import->user;

        /*
        | **EVERY REFUSAL BECOMES A ROW FAILURE, INCLUDING THE ONES THAT ARE NOT
        | ABOUT THE ROW.** The API answers 403 for the whole call because there is
        | a caller waiting; here the caller left hours ago, and an exception that
        | escapes this method fails the JOB — so Filament records the row with an
        | EMPTY reason and hands the queue the whole chunk to retry (ADR-075).
        |
        | That is not theory: a seller whose shop was still a draft uploaded 1,321
        | rows and got 3,963 blank failures and no completion notification at all,
        | because `noSellableStore` was thrown one line above this block, outside
        | any catch. The identity lookup belongs INSIDE the guard for the same
        | reason the policy check does. Every row then carries the same clear
        | sentence, which is a perfectly good report — and the import finishes, so
        | the seller is actually told.
        */
        try {
            $seller = app(SellerFeedIdentity::class)->forUser((int) $this->import->user_id);

            app(SellerFeedGate::class)->assertMayWriteFor($importer, $seller['orgId']);
        } catch (OfferFeedException|AuthorizationException $exception) {
            throw new RowImportFailedException($exception->getMessage());
        }

        $currency = app(CurrencyRepositoryContract::class)->default();

        $gtin = trim((string) ($this->data['gtin'] ?? ''));

        try {
            app(SyncSellerOfferAction::class)->run(new SyncOfferDTO(
                sellingOrgId: $seller['orgId'],
                sellingOrgUuid: $seller['orgUuid'],
                storeUuid: $seller['storeUuid'],
                gtin: $gtin,
                priceMinor: $this->minor($currency, $this->data['fiyat'] ?? null),
                stockQuantity: isset($this->data['stok']) ? (int) $this->data['stok'] : null,
                listPriceMinor: $this->minor($currency, $this->data['liste_fiyati'] ?? null),
            ));
        } catch (OfferFeedException $exception) {
            throw new RowImportFailedException($exception->getMessage());
        }

        /*
        | THE OFFER, RE-READ THROUGH THE CORE CONTRACT. The action returns an
        | outcome rather than a model — deliberately, since "unchanged" has no
        | model to return — and Filament wants a record.
        |
        | **THE BARCODE IS RESOLVED THROUGH `CatalogQueryContract`, NOT A RELATION.**
        | An `Offer` has no `variant` relation and must not grow one: the variant is
        | Catalog's model, Offer imports no module (ADR-042), and `LayeringTest`
        | fails the build on it. The offer carries `variant_uuid` as a plain string
        | for exactly this reason.
        */
        $variantUuid = app(CatalogQueryContract::class)->publishedVariantUuidForGtin($gtin);

        return app(OfferRepositoryContract::class)
            ->anyForSellerAndVariant($seller['orgId'], (string) $variantUuid)
            ?? new Offer;
    }

    /**
     * NOTHING. `fiyat` is not a column on `offers`.
     */
    public function fillRecord(): void {}

    /**
     * NOTHING. The offer actions already committed.
     */
    public function saveRecord(): void {}

    /**
     * Ten minutes, not Filament's default day (ADR-075's outer fence).
     */
    public function getJobRetryUntil(): CarbonInterface
    {
        return now()->addMinutes(10);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        return __('offer.feed.completed', [
            'imported' => number_format($import->successful_rows),
            'failed' => number_format($import->getFailedRowsCount()),
        ]);
    }

    /**
     * A decimal cell → kuruş, or null when the column was left empty.
     *
     * THE COMMA IS TURKISH AND EXCEL WRITES IT, so normalising it here is the
     * difference between a working upload and a page of rejected rows.
     */
    private function minor(mixed $currency, mixed $value): ?int
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        return $currency->toMinor(str_replace(',', '.', $text));
    }
}
