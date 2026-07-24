<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource\Pages;

use App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewOrganization extends ViewRecord
{
    protected static string $resource = OrganizationResource::class;
}
