<?php

declare(strict_types=1);

namespace Database\Modules\Loyalty\Factories;

use App\Modules\Loyalty\Domain\Enums\LoyaltyPointSource;
use App\Modules\Loyalty\Domain\Models\LoyaltyLedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LoyaltyLedgerEntry>
 */
final class LoyaltyLedgerEntryFactory extends Factory
{
    protected $model = LoyaltyLedgerEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_uuid' => (string) Str::uuid(),
            'points' => fake()->numberBetween(1, 500),
            'source_type' => LoyaltyPointSource::Purchase,
            // UNIQUE PER ROW BY DEFAULT: the table's idempotency key is
            // (source_type, source_uuid), so a factory that repeated one would
            // fail the second `create()` for a reason that has nothing to do with
            // the test.
            'source_uuid' => (string) Str::uuid(),
            'meta' => null,
            'created_at' => now(),
        ];
    }

    public function for_(string $customerUuid): self
    {
        return $this->state(fn (): array => ['customer_uuid' => $customerUuid]);
    }

    public function source(LoyaltyPointSource $source, string $sourceUuid): self
    {
        return $this->state(fn (): array => ['source_type' => $source, 'source_uuid' => $sourceUuid]);
    }
}
