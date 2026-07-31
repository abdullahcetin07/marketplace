<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Filament\Resources\OrderResource\Pages;

use App\Modules\Order\Presentation\Filament\Resources\OrderResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Orders across every seller. Nothing an operator creates here.
 */
final class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
