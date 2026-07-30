<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Filament\Resources\StockResource\Pages;

use App\Modules\Inventory\Presentation\Filament\Resources\StockResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * One seller's pool and its ledger — the page a support ticket is answered from.
 *
 * No edit action, and not because one was forgotten: there is no operator write
 * on a merchant's stock at all (§7).
 */
final class ViewStock extends ViewRecord
{
    protected static string $resource = StockResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
