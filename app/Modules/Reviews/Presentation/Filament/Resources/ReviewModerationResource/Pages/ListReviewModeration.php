<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Presentation\Filament\Resources\ReviewModerationResource\Pages;

use App\Modules\Reviews\Presentation\Filament\Resources\ReviewModerationResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The queue. No header action: reviews arrive from buyers, not from staff.
 */
final class ListReviewModeration extends ListRecords
{
    protected static string $resource = ReviewModerationResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
