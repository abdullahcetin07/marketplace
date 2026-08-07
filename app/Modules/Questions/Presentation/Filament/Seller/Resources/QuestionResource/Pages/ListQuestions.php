<?php

declare(strict_types=1);

namespace App\Modules\Questions\Presentation\Filament\Seller\Resources\QuestionResource\Pages;

use App\Modules\Questions\Presentation\Filament\Seller\Resources\QuestionResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The merchant's queue. No header action: questions come from shoppers.
 */
final class ListQuestions extends ListRecords
{
    protected static string $resource = QuestionResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
