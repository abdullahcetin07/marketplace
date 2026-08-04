<?php

declare(strict_types=1);

namespace Database\Modules\Payment\Factories;

use App\Modules\Payment\Domain\Enums\LedgerEntryType;
use App\Modules\Payment\Domain\Models\SellerLedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SellerLedgerEntry>
 */
final class SellerLedgerEntryFactory extends Factory
{
    protected $model = SellerLedgerEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seller_org_uuid' => (string) Str::uuid(),
            'type' => LedgerEntryType::SaleCredit,
            // Signed by the type, exactly as a real entry is — a factory that
            // produced an unsigned debit would make every test using it lie about
            // the balance.
            'amount_minor' => LedgerEntryType::SaleCredit->signedAmount(12_990),
        ];
    }

    public function of(LedgerEntryType $type, int $magnitudeMinor): self
    {
        return $this->state(fn (): array => [
            'type' => $type,
            'amount_minor' => $type->signedAmount($magnitudeMinor),
        ]);
    }

    public function forSeller(string $sellerOrgUuid): self
    {
        return $this->state(fn (): array => ['seller_org_uuid' => $sellerOrgUuid]);
    }
}
