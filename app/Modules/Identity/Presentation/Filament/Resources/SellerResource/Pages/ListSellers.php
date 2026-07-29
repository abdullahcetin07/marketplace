<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Filament\Resources\SellerResource\Pages;

use App\Modules\Identity\Presentation\Filament\Resources\SellerResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The merchant listing. No header action: merchants self-register, and this
 * area exists to oversee them, not to provision them.
 */
final class ListSellers extends ListRecords
{
    protected static string $resource = SellerResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
