<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Filament\Resources\CommissionRuleResource\Pages;

use App\Modules\Payment\Presentation\Filament\Resources\CommissionRuleResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCommissionRule extends CreateRecord
{
    protected static string $resource = CommissionRuleResource::class;
}
