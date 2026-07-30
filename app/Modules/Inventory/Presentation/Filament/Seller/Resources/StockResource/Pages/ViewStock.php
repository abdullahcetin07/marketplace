<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Filament\Seller\Resources\StockResource\Pages;

use App\Modules\Inventory\Presentation\Filament\Seller\Resources\StockResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * One stock pool and its ledger — where a seller finds out WHY their numbers are
 * what they are.
 *
 * The movement history is the whole reason ADR-050 chose an append-only ledger
 * over a mutable counter: "the system says 3 and I never sold that many" is
 * answerable here and nowhere else.
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
