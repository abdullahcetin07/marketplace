<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Resources\AttributeResource\Pages;

use App\Modules\Catalog\Presentation\Filament\Resources\AttributeResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Label and flag edits only — `code` and `type` are disabled on this form.
 *
 * There is no UpdateAttribute action in §12, and none is invented here: the
 * editable fields carry no cross-record rule (the one rule that matters,
 * "variant-defining needs an enumerable type", is fixed at creation because the
 * type is immutable). A plain model save is honest about that.
 */
final class EditAttribute extends EditRecord
{
    protected static string $resource = AttributeResource::class;

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('index');
    }
}
