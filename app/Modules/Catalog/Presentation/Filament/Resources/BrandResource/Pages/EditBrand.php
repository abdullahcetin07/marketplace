<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Resources\BrandResource\Pages;

use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Presentation\Filament\Resources\BrandResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditBrand extends EditRecord
{
    protected static string $resource = BrandResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    /**
     * The logo is a MEDIA write, so it is pulled out of the payload before the
     * record is saved.
     *
     * Left in, it would be mass-assigned as a `logo` column that does not
     * exist. Media is not a column on this model at all — it is a row in
     * `media` pointing at the public disk — which is exactly why it travels
     * through an action rather than through the save.
     *
     * NO UPLOAD MEANS NO CHANGE. An edit that only renames the brand leaves the
     * field empty and `attachLogo()` does nothing, because the alternative —
     * reading "absent" as "clear it" — would delete the logo every time
     * somebody fixed a typo.
     *
     * @param  Brand  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $logo = $data['logo'] ?? null;
        unset($data['logo']);

        $record->update($data);

        BrandResource::attachLogo($record, ['logo' => $logo]);

        return $record;
    }
}
