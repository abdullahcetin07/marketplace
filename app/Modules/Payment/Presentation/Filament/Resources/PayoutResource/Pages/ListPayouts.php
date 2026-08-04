<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Filament\Resources\PayoutResource\Pages;

use App\Modules\Payment\Presentation\Filament\Resources\PayoutResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListPayouts extends ListRecords
{
    protected static string $resource = PayoutResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
