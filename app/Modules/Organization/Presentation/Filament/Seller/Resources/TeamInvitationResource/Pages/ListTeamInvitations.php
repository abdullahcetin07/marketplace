<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Seller\Resources\TeamInvitationResource\Pages;

use App\Modules\Organization\Presentation\Filament\Seller\Resources\TeamInvitationResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Invitations in flight. Issuing one is the members list's action — this page
 * is where an issued invitation is watched, re-sent or withdrawn.
 */
final class ListTeamInvitations extends ListRecords
{
    protected static string $resource = TeamInvitationResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
