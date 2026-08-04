<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Filament\Resources\PaymentAdminResource\Pages;

use App\Modules\Payment\Presentation\Filament\Resources\PaymentAdminResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The list, and the only page this resource has. There is no create page and no
 * edit page: a payment is a record of what a bank did.
 */
final class ListPayments extends ListRecords
{
    protected static string $resource = PaymentAdminResource::class;
}
