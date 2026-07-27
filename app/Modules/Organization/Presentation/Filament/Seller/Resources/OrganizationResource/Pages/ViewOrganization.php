<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource\Pages;

use App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * The company detail page — the hub of seller onboarding.
 *
 * Editing the profile, submitting KYC and setting the payout account each live
 * behind their own header action because each is a DIFFERENT capability and a
 * different module Action; collapsing them into one save would blur three
 * authorization decisions into one.
 */
final class ViewOrganization extends ViewRecord
{
    protected static string $resource = OrganizationResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
