<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Resources\ProductModerationResource\Pages;

use App\Modules\Catalog\Presentation\Filament\Resources\ProductModerationResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * The full proposal, read-only. A verdict made without seeing the variants and
 * the images is a guess, so this exists; an edit form does not, because a
 * moderator fixing a product removes the seller's chance to learn what was
 * wrong.
 */
final class ViewProductModeration extends ViewRecord
{
    protected static string $resource = ProductModerationResource::class;
}
