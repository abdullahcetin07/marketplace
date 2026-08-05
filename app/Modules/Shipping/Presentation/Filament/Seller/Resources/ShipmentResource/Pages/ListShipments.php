<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Presentation\Filament\Seller\Resources\ShipmentResource\Pages;

use App\Modules\Shipping\Presentation\Filament\Seller\Resources\ShipmentResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The list, and the only page this resource has. A shipment is created by a paid
 * order and changes only through its one action.
 */
final class ListShipments extends ListRecords
{
    protected static string $resource = ShipmentResource::class;
}
