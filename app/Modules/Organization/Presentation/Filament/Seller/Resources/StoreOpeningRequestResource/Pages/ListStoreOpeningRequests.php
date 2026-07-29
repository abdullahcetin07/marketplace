<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Seller\Resources\StoreOpeningRequestResource\Pages;

use App\Modules\Organization\Presentation\Filament\Seller\Resources\StoreOpeningRequestResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The seller's store-opening requests, across every company they belong to.
 *
 * A STATUS VIEW (owner-approved reflow): where a request stands — pending,
 * approved, rejected — and the two things a seller can still do to one, submit
 * and withdraw. No "new request" button, because a request is now raised where
 * the seller is already thinking about it: with their company on the onboarding
 * form, or from "Mağazalarım" for an additional store.
 */
final class ListStoreOpeningRequests extends ListRecords
{
    protected static string $resource = StoreOpeningRequestResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
