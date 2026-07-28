<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Resources\ProductModerationResource\Pages;

use App\Modules\Catalog\Presentation\Filament\Resources\ProductModerationResource;
use Filament\Resources\Pages\ListRecords;

/**
 * No header actions: nothing is created from the queue (§5).
 */
final class ListProductModeration extends ListRecords
{
    protected static string $resource = ProductModerationResource::class;
}
