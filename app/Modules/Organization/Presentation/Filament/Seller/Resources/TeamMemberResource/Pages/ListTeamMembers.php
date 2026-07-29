<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Seller\Resources\TeamMemberResource\Pages;

use App\Modules\Organization\Presentation\Filament\Seller\Resources\TeamMemberResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The team listing.
 *
 * No "New" header action: a colleague is INVITED, never created. The invite
 * lives on the table's header action because it is not a record-creation form —
 * it produces an invitation, and the membership appears only once the person
 * accepts it (ADR-031).
 */
final class ListTeamMembers extends ListRecords
{
    protected static string $resource = TeamMemberResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
