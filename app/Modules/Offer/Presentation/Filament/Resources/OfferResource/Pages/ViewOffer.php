<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Filament\Resources\OfferResource\Pages;

use App\Modules\Offer\Presentation\Filament\Resources\OfferResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * One offer, read-only, plus its suspension record if it has one.
 *
 * Suspend and reinstate are reasoned row actions on the listing; there are no
 * header actions here, so neither can happen without an explicit confirmation
 * and a reason.
 */
final class ViewOffer extends ViewRecord
{
    protected static string $resource = OfferResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
