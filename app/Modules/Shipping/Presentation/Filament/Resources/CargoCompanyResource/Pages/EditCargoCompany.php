<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Presentation\Filament\Resources\CargoCompanyResource\Pages;

use App\Modules\Shipping\Presentation\Filament\Resources\CargoCompanyResource;
use Filament\Resources\Pages\EditRecord;

/**
 * No delete action: a carrier is retired with `is_active`, never removed — a
 * shipment's history must keep saying who carried it.
 */
final class EditCargoCompany extends EditRecord
{
    protected static string $resource = CargoCompanyResource::class;
}
