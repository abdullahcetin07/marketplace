<?php

declare(strict_types=1);

namespace App\Modules\Questions\Presentation\Filament\Resources\QuestionModerationResource\Pages;

use App\Modules\Questions\Presentation\Filament\Resources\QuestionModerationResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Every question on the platform. No header action: staff neither ask nor answer.
 */
final class ListQuestionModeration extends ListRecords
{
    protected static string $resource = QuestionModerationResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
