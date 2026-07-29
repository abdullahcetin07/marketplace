<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Filament\Resources\SellerResource\Pages;

use App\Modules\Identity\Presentation\Filament\Resources\SellerResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * A merchant account, read-only, with its forensic login history.
 *
 * No header actions: this page is for reading. The two changes an operator may
 * make — suspend and reinstate — are reasoned row actions on the listing, so
 * neither can happen without an explicit confirmation and an audit reason.
 */
final class ViewSeller extends ViewRecord
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
