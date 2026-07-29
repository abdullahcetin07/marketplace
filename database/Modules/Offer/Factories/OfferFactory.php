<?php

declare(strict_types=1);

namespace Database\Modules\Offer\Factories;

use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Models\Offer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Offer>
 */
final class OfferFactory extends Factory
{
    protected $model = Offer::class;

    /**
     * EVERY FOREIGN REFERENCE IS A BARE UUID, and the factory invents them
     * rather than reaching for a Catalog, Organization or Store factory — Offer
     * imports none of those (ADR-046), and a factory that did would smuggle the
     * dependency in through the test suite's back door. A test that needs a real
     * variant creates one itself and passes its uuid in.
     *
     * The currency is the one exception: it is a real Localization row, because
     * money without a currency is not money. Tests that touch it seed the
     * platform first (`$this->seedPlatform()`).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'variant_uuid' => (string) Str::uuid(),
            'product_uuid' => (string) Str::uuid(),
            'selling_org_id' => fake()->numberBetween(1, 1000),
            'selling_org_uuid' => (string) Str::uuid(),
            'store_uuid' => (string) Str::uuid(),
            // Between ₺10 and ₺5.000, in kuruş. Never a float.
            'price_minor' => fake()->numberBetween(1_000, 500_000),
            'list_price_minor' => null,
            /*
            | The PLATFORM DEFAULT, not "whichever currency row is first". An
            | offer is priced in the platform default this sprint (§13.1), and a
            | factory that picked an arbitrary currency would make every money
            | assertion depend on seeding order.
            */
            'currency_id' => Currency::query()->where('is_default', true)->value('id')
                ?? Currency::query()->value('id')
                ?? Currency::factory(),
            'stock_quantity' => fake()->numberBetween(1, 50),
            'status' => OfferStatus::Active,
            'status_before_suspension' => null,
            'paused_by_cascade' => false,
        ];
    }

    /**
     * Priced exactly, in minor units — the readable way for a buy-box test to
     * say "this one is cheaper".
     */
    public function priced(int $priceMinor, ?int $listPriceMinor = null): static
    {
        return $this->state(fn (): array => [
            'price_minor' => $priceMinor,
            'list_price_minor' => $listPriceMinor,
        ]);
    }

    /**
     * Sold out — still Active, because out-of-stock is derived from the
     * quantity and is not a status (ADR-043).
     */
    public function outOfStock(): static
    {
        return $this->state(fn (): array => ['stock_quantity' => 0]);
    }

    public function status(OfferStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => ['status' => OfferStatus::Paused]);
    }

    /**
     * Suspended by an admin, carrying the state to restore on reinstatement —
     * the Store suspension shape (§3.1).
     */
    public function suspended(OfferStatus $before = OfferStatus::Active): static
    {
        return $this->state(fn (): array => [
            'status' => OfferStatus::Suspended,
            'status_before_suspension' => $before,
            'suspended_at' => now(),
        ]);
    }

    /**
     * Belonging to one selling organization — the ADR-040 id/uuid pair set
     * together, because a test that sets only one of them proves nothing about
     * the tenancy wall.
     */
    public function forOrganization(int $organizationId, string $organizationUuid): static
    {
        return $this->state(fn (): array => [
            'selling_org_id' => $organizationId,
            'selling_org_uuid' => $organizationUuid,
        ]);
    }

    public function forVariant(string $variantUuid, string $productUuid): static
    {
        return $this->state(fn (): array => [
            'variant_uuid' => $variantUuid,
            'product_uuid' => $productUuid,
        ]);
    }

    public function forStore(string $storeUuid): static
    {
        return $this->state(fn (): array => ['store_uuid' => $storeUuid]);
    }
}
