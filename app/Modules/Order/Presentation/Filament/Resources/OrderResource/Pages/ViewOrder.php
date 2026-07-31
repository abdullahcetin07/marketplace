<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Filament\Resources\OrderResource\Pages;

use App\Modules\Order\Presentation\Filament\Resources\OrderResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * One order, from the platform's side — the page a "where is my order" ticket is
 * answered from, and the one place a checkout group can be read off and pasted
 * back into the search to find the rest of the purchase (ADR-052).
 */
final class ViewOrder extends ViewRecord
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
