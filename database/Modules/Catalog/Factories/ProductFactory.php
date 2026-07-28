<?php

declare(strict_types=1);

namespace Database\Modules\Catalog\Factories;

use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->words(3, true);

        return [
            'uuid' => (string) Str::uuid(),
            'category_id' => CategoryFactory::new(),
            'brand_id' => null,
            'title_tr' => Str::title($title),
            'title_en' => Str::title($title),
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1, 999999),
            'description_tr' => fake()->sentence(),
            'description_en' => fake()->sentence(),
            // Null rather than a random one: most products in the catalog have
            // no barcode, and a factory that always set one would make the
            // GTIN-uniqueness tests pass for the wrong reason.
            'gtin' => null,
            'status' => ProductStatus::Draft,
            'proposed_by_org_id' => null,
            'proposed_by_org_uuid' => null,
        ];
    }

    /**
     * A proposal authored by a seller — the state the seller panel scopes on
     * (ADR-030/040).
     *
     * Takes the Organization's ID AND UUID together rather than either alone,
     * because a product carrying one without the other is a row no production
     * code path can produce — and a factory that could produce it would let a
     * test pass against a state that cannot exist.
     */
    public function proposedBy(int $organizationId, string $organizationUuid): static
    {
        return $this->state(fn (): array => [
            'proposed_by_org_id' => $organizationId,
            'proposed_by_org_uuid' => $organizationUuid,
        ]);
    }

    public function pendingReview(): static
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::PendingReview,
            'submitted_at' => now(),
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::Published,
            'submitted_at' => now()->subDay(),
            'moderated_at' => now(),
            'published_at' => now(),
        ]);
    }

    public function needsRevision(string $reason = 'Fotoğraflar bulanık.'): static
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::NeedsRevision,
            'moderated_at' => now(),
            'moderation_reason' => $reason,
        ]);
    }

    public function rejected(string $reason = 'Katalogda zaten mevcut.'): static
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::Rejected,
            'moderated_at' => now(),
            'moderation_reason' => $reason,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => ProductStatus::Archived]);
    }

    /**
     * With the single default variant every product must have (ADR-039), so a
     * test that does not care about variants still gets a valid product.
     */
    public function withDefaultVariant(): static
    {
        return $this->afterCreating(function (Product $product): void {
            ProductVariantFactory::new()->for($product)->default()->create();
        });
    }
}
