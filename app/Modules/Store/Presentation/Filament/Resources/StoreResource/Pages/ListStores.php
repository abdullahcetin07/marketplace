<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation\Filament\Resources\StoreResource\Pages;

use App\Modules\Store\Presentation\Filament\Resources\StoreResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The admin store list. No "new" — stores are created event-driven from an
 * approved Store Opening Request (ADR-028), never by an operator.
 */
final class ListStores extends ListRecords
{
    protected static string $resource = StoreResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
