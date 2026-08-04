<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Filament\Resources\PayoutResource\Pages;

use App\Modules\Payment\Application\Actions\CreatePayoutAction;
use App\Modules\Payment\Domain\Models\Payout;
use App\Modules\Payment\Presentation\Filament\Resources\PayoutResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Creating a payout goes through the ACTION, not through Filament's own save.
 *
 * That is not ceremony: the action takes the row lock, checks the balance and
 * appends the `payout_debit` in one transaction. Letting the panel write the row
 * directly would create a payout that never debited anything — a promise of money
 * with nothing held against it.
 */
final class CreatePayout extends CreateRecord
{
    protected static string $resource = PayoutResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreatePayoutAction::class)->run(
            (string) $data['seller_org_uuid'],
            (int) $data['amount_minor'],
            (int) auth()->id(),
            $data['note'] ?? null,
        );
    }

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('index');
    }
}
