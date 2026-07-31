<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Filament\Seller\Resources\OrderResource\Pages;

use App\Modules\Order\Presentation\Filament\Seller\Resources\OrderResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The seller's orders. No header action: orders are made by customers.
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
