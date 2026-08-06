<?php

declare(strict_types=1);

namespace Database\Modules\Reviews\Factories;

use App\Modules\Reviews\Domain\Enums\ReviewStatus;
use App\Modules\Reviews\Domain\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Review>
 *
 * **EVERY FOREIGN IDENTIFIER IS INVENTED, NOT FACTORIED.** There is no
 * `Product::factory()` or `Order::factory()` here, and that is the module
 * boundary reaching into the test support: Reviews imports no module, so its
 * factory may not either — `LayeringTest` covers `Database\Modules\Reviews` too.
 * A review is a bag of uuids pointing outward, and a test that needs them to
 * point at real rows supplies them.
 */
final class ReviewFactory extends Factory
{
    protected $model = Review::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_uuid' => (string) Str::uuid(),
            'variant_uuid' => (string) Str::uuid(),
            // UNIQUE in the schema, so every made review must get its own.
            'order_line_uuid' => (string) Str::uuid(),
            'customer_id' => 1,
            'customer_uuid' => (string) Str::uuid(),
            // Already masked, because the column is (see the model).
            'author_name' => 'Ayşe Y.',
            'store_uuid' => (string) Str::uuid(),
            'selling_org_uuid' => (string) Str::uuid(),
            'rating' => fake()->numberBetween(1, 5),
            'body' => 'Ürün açıklamasıyla birebir aynı, memnun kaldım.',
            'status' => ReviewStatus::PendingReview,
            'has_photos' => false,
        ];
    }

    public function published(): self
    {
        return $this->state(fn (): array => [
            'status' => ReviewStatus::Published,
            'moderated_at' => now(),
            'moderated_by' => 1,
        ]);
    }

    public function pending(): self
    {
        return $this->state(fn (): array => ['status' => ReviewStatus::PendingReview]);
    }

    public function rejected(): self
    {
        return $this->state(fn (): array => [
            'status' => ReviewStatus::Rejected,
            'moderated_at' => now(),
            'moderated_by' => 1,
            'moderation_reason' => 'Ürünle ilgisi yok',
        ]);
    }

    public function forProduct(string $productUuid): self
    {
        return $this->state(fn (): array => ['product_uuid' => $productUuid]);
    }

    public function forCustomer(int $customerId, string $customerUuid): self
    {
        return $this->state(fn (): array => [
            'customer_id' => $customerId,
            'customer_uuid' => $customerUuid,
        ]);
    }

    public function forStore(string $storeUuid): self
    {
        return $this->state(fn (): array => ['store_uuid' => $storeUuid]);
    }

    public function withRating(int $rating): self
    {
        return $this->state(fn (): array => ['rating' => $rating]);
    }

    public function withPhotos(): self
    {
        // The FLAG only — attaching real media is `CreateReviewAction`'s job and
        // needs a file. This is what the "sadece resimli" filter reads.
        return $this->state(fn (): array => ['has_photos' => true]);
    }
}
