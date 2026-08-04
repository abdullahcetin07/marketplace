<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Filament\Resources\CommissionRuleResource\Pages;

use App\Modules\Payment\Presentation\Filament\Resources\CommissionRuleResource;
use Filament\Resources\Pages\EditRecord;

final class EditCommissionRule extends EditRecord
{
    protected static string $resource = CommissionRuleResource::class;
}
