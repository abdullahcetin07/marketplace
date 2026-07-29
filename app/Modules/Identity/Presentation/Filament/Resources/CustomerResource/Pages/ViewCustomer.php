<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Filament\Resources\CustomerResource\Pages;

use App\Modules\Identity\Presentation\Filament\Resources\CustomerResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * A shopper account, read-only, with its forensic login history. Suspend and
 * reinstate are reasoned row actions on the listing. @see ViewSeller
 */
final class ViewCustomer extends ViewRecord
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
