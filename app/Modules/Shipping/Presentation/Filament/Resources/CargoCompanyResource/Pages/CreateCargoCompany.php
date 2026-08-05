<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Presentation\Filament\Resources\CargoCompanyResource\Pages;

use App\Modules\Shipping\Presentation\Filament\Resources\CargoCompanyResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCargoCompany extends CreateRecord
{
    protected static string $resource = CargoCompanyResource::class;
}
