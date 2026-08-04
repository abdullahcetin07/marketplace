<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Filament\Seller\Resources\OfferResource\Pages;

use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Offer\Application\Actions\UpdateOfferPriceAction;
use App\Modules\Offer\Application\Actions\UpdateOfferStockAction;
use App\Modules\Offer\Domain\DTOs\UpdateOfferPriceDTO;
use App\Modules\Offer\Domain\DTOs\UpdateOfferStockDTO;
use App\Modules\Offer\Domain\Models\Offer;
use App\Modules\Offer\Presentation\Filament\Seller\Resources\OfferResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Re-pricing and restocking — the two edits an offer supports.
 *
 * ONE FORM, TWO ACTIONS, and that is deliberate rather than clumsy. Price and
 * stock are separate facts with separate events (`OfferPriceChanged` moves the
 * buy box; `OfferStockChanged` only flips sellability), so a single "update
 * offer" write would make every restock look like a re-price to every
 * downstream consumer. The form is a convenience for the seller; the split
 * behind it is the contract with everyone else.
 *
 * Each action runs only when its own fact actually changed, so saving the form
 * after editing stock alone does not emit a price event with identical before
 * and after values.
 *
 * MONEY IS CONVERTED AT THIS BOUNDARY and nowhere deeper — the form speaks
 * 129,90 and the DTO speaks 12990 (non-negotiable #6).
 */
final class EditOffer extends EditRecord
{
    protected static string $resource = OfferResource::class;

    /**
     * Minor units become major for display. The record's own currency, not the
     * platform default: an offer priced before a currency change still renders
     * against the one it was priced in.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Offer $offer */
        $offer = $this->getRecord();

        $data['price'] = $offer->currency->toMajor($offer->price_minor);
        $data['list_price'] = $offer->list_price_minor === null
            ? null
            : $offer->currency->toMajor($offer->list_price_minor);

        return $data;
    }

    /**
     * @param Offer $record
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $reason = $data['reason'] ?? null;
        $reason = is_string($reason) && $reason !== '' ? $reason : null;

        $currency = $record->currency ?? app(CurrencyRepositoryContract::class)->default();

        $priceMinor = $currency->toMinor((string) $data['price']);
        $listPriceMinor = ($data['list_price'] ?? null) === null || $data['list_price'] === ''
            ? null
            : $currency->toMinor((string) $data['list_price']);

        if ($priceMinor !== $record->price_minor || $listPriceMinor !== $record->list_price_minor) {
            $record = app(UpdateOfferPriceAction::class)->run($record, new UpdateOfferPriceDTO(
                priceMinor: $priceMinor,
                listPriceMinor: $listPriceMinor,
                // Always present: the form always submits the field, so "not
                // supplied" is not a state this surface can produce.
                present: ['list_price_minor'],
                reason: $reason,
            ));
        }

        $stock = (int) $data['stock_quantity'];

        if ($stock !== $record->stock_quantity) {
            $record = app(UpdateOfferStockAction::class)->run($record, new UpdateOfferStockDTO(
                stockQuantity: $stock,
                reason: $reason,
            ));
        }

        return $record;
    }

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        // Pause, resume and withdraw live on the listing, where they read as
        // decisions about a listing rather than as buttons on an edit form.
        return [];
    }
}
