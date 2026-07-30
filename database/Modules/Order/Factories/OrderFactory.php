<?php

declare(strict_types=1);

namespace Database\Modules\Order\Factories;

use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
final class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * Every foreign reference is an invented uuid — Order imports no module
     * (ADR-052…056), and a factory that reached for a Customer, Organization or
     * Store model would smuggle the dependency in through the test suite.
     *
     * The currency is the one exception: it is a real Localization row, because
     * money without a currency is not money. Tests that touch it seed the platform
     * first (`$this->seedPlatform()`).
     *
     * TOTALS DEFAULT TO ZERO rather than to a random number. A real order's totals
     * are the sum of its lines (§3.5), so an invented total would be a state no
     * checkout can produce — `withLines()` is how a test gets consistent ones.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'order_number' => 'SP-'.mb_strtoupper(Str::random(10)),
            // Its own group by default: one order IS a valid purchase (a
            // single-seller basket), and a test about the split says otherwise.
            'checkout_group_uuid' => (string) Str::uuid(),
            'customer_id' => fake()->numberBetween(1, 1000),
            'customer_uuid' => (string) Str::uuid(),
            'selling_org_uuid' => (string) Str::uuid(),
            'store_uuid' => (string) Str::uuid(),
            'status' => OrderStatus::Pending,
            /*
            | The PLATFORM DEFAULT, not "whichever currency row is first" — the
            | `OfferFactory` lesson: picking an arbitrary currency makes every
            | money assertion depend on seeding order.
            */
            'currency_id' => Currency::query()->where('is_default', true)->value('id')
                ?? Currency::query()->value('id')
                ?? Currency::factory(),
            'items_total_minor' => 0,
            'tax_total_minor' => 0,
            'grand_total_minor' => 0,
            'shipping_address' => self::addressSnapshot(),
            'billing_address' => self::addressSnapshot(),
            'placed_at' => null,
            'cancelled_at' => null,
        ];
    }

    /**
     * The frozen shape `CustomerAddress::toSnapshot()` produces (ADR-056) —
     * duplicated here rather than generated from the model, deliberately: a
     * factory that built it by calling the model would stop catching the case
     * where the two shapes drift, which is the thing worth catching.
     *
     * @return array<string, string|null>
     */
    private static function addressSnapshot(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'label' => 'Ev',
            'recipient_name' => fake()->name(),
            'phone' => '+90555'.fake()->numerify('#######'),
            'line1' => fake()->streetAddress(),
            'line2' => null,
            'district' => 'Kadıköy',
            'city' => 'İstanbul',
            'postal_code' => fake()->numerify('#####'),
            'country_code' => 'TR',
            'country_name' => 'Türkiye',
        ];
    }

    public function forCustomer(int $customerId, string $customerUuid): static
    {
        return $this->state(fn (): array => [
            'customer_id' => $customerId,
            'customer_uuid' => $customerUuid,
        ]);
    }

    /**
     * Belonging to one seller — the tenancy filter the seller panel scopes on.
     */
    public function forSeller(string $sellingOrgUuid, ?string $storeUuid = null): static
    {
        return $this->state(fn (): array => [
            'selling_org_uuid' => $sellingOrgUuid,
            'store_uuid' => $storeUuid ?? (string) Str::uuid(),
        ]);
    }

    /**
     * Part of one purchase — how a SPLIT test says "these two orders are one
     * customer's single checkout" (ADR-052).
     */
    public function inCheckoutGroup(string $checkoutGroupUuid): static
    {
        return $this->state(fn (): array => ['checkout_group_uuid' => $checkoutGroupUuid]);
    }

    public function status(OrderStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    /**
     * Placed: stock committed, awaiting payment (ADR-054).
     */
    public function placed(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::AwaitingPayment,
            'placed_at' => now(),
        ]);
    }

    public function cancelled(?string $reason = null): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);
    }

    /**
     * A `Pending` order whose reservation window has already run out — the state
     * the expiry sweep looks for (§3.3).
     */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Pending,
            'created_at' => now()->subMinutes(
                (int) config('order.reservation.expires_after_minutes', 30) + 5,
            ),
        ]);
    }

    /**
     * Totals set to exact amounts, in minor units — the readable way for a money
     * test to say what it means. Never a float.
     */
    public function totalling(int $itemsMinor, int $taxMinor): static
    {
        return $this->state(fn (): array => [
            'items_total_minor' => $itemsMinor,
            'tax_total_minor' => $taxMinor,
            // No shipping and no discount this sprint (§3.4).
            'grand_total_minor' => $itemsMinor,
        ]);
    }
}
