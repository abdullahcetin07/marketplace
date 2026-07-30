<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Resources\TaxRateResource\Pages;

use App\Modules\Catalog\Presentation\Filament\Resources\TaxRateResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * A plain create, unlike Brand's and Category's.
 *
 * There is no action behind it because there is nothing for one to do: no slug to
 * derive, no event anyone subscribes to, no second table to touch. The audit
 * trail comes from the model's `Auditable` concern, so the change is still
 * attributable — which is the only thing an action would have added here.
 */
final class CreateTaxRate extends CreateRecord
{
    protected static string $resource = TaxRateResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
