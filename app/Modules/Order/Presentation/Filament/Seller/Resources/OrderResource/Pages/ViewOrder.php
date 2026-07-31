<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Filament\Seller\Resources\OrderResource\Pages;

use App\Modules\Order\Presentation\Filament\Seller\Resources\OrderResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * One order, with what was bought and where it is going.
 *
 * No edit action, and not because one was forgotten: the lines are immutable and
 * the totals were written once (ADR-053).
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
