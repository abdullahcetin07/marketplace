<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Resources\TaxRateResource\Pages;

use App\Modules\Catalog\Presentation\Filament\Resources\TaxRateResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Editing a bracket changes what is charged on everything sold under it FROM NOW
 * ON, and nothing that was sold before: order lines snapshot the rate at
 * placement (ADR-053). That is the property that makes editing a live tax rate
 * safe enough to be a plain form.
 *
 * No delete action — a repealed bracket is deactivated (@see TaxRateResource).
 */
final class EditTaxRate extends EditRecord
{
    protected static string $resource = TaxRateResource::class;

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('index');
    }

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
