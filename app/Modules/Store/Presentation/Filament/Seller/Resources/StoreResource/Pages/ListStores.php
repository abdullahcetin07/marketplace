<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation\Filament\Seller\Resources\StoreResource\Pages;

use App\Modules\Store\Presentation\Filament\Seller\Resources\StoreResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Route;

/**
 * The seller store list — "Mağazalarım".
 *
 * STILL NO "NEW STORE". A store is created only from an approved Store Opening
 * Request (ADR-028); the seller raises the request, never the store. What this
 * page gained is the RELOCATED entry point for raising one (owner-approved
 * reflow): a seller looking at their stores and wanting another is already in
 * the right frame of mind, and the standalone nav item they used to hunt for is
 * gone.
 *
 * A LINK, NOT A FORM, and that is the boundary showing. The request belongs to
 * Organization; Store may not import it (ADR-033), so this cannot embed the
 * form or call the action. It points at the page that owns it — by ROUTE NAME,
 * the same class-string-style coupling Offer uses to subscribe to Catalog's
 * events, and for the same reason: a name is not an import.
 *
 * The cost, stated rather than hidden: a rename on the Organization side breaks
 * the link at runtime rather than at build time. `Route::has()` bounds that — the
 * button disappears instead of 500ing — and a feature test asserts the route
 * exists, which is the only thing that would notice.
 */
final class ListStores extends ListRecords
{
    /**
     * The seller-panel route of the store-opening-request form.
     */
    private const string REQUEST_ROUTE = 'filament.seller.resources.store-opening-requests.create';

    protected static string $resource = StoreResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        if (! Route::has(self::REQUEST_ROUTE)) {
            return [];
        }

        return [
            Actions\Action::make('request_store')
                ->label(__('store.action.request'))
                ->icon('heroicon-o-plus-circle')
                ->url(route(self::REQUEST_ROUTE)),
        ];
    }
}
