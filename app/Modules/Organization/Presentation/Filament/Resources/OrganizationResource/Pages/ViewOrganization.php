<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Resources\OrganizationResource\Pages;

use App\Modules\Organization\Presentation\Filament\Resources\OrganizationResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * An organization's detail, for review. Lifecycle decisions are the row/table
 * actions defined on the resource, delegating to the module actions.
 */
final class ViewOrganization extends ViewRecord
{
    protected static string $resource = OrganizationResource::class;
}
