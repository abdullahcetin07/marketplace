<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Filament\Resources\CustomerResource\Pages;

use App\Modules\Identity\Presentation\Filament\Resources\CustomerResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The shopper listing. No header action — customers register themselves.
 */
final class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
