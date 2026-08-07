<?php

declare(strict_types=1);

namespace Database\Modules\Questions\Factories;

use App\Modules\Questions\Domain\Enums\QuestionStatus;
use App\Modules\Questions\Domain\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Question>
 *
 * EVERY FOREIGN IDENTIFIER IS INVENTED, NOT FACTORIED — no `Product::factory()`
 * and no `Store::factory()` here. `LayeringTest` covers
 * `Database\Modules\Questions` too, so the test support keeps the same boundary
 * the module does: a question is a bag of uuids pointing outward, and a test
 * that needs them to point at real rows supplies them.
 */
final class QuestionFactory extends Factory
{
    protected $model = Question::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_uuid' => (string) Str::uuid(),
            'customer_id' => 1,
            'customer_uuid' => (string) Str::uuid(),
            // Already masked, because the column is (see the model).
            'asker_name' => 'Ayşe Y.',
            'store_uuid' => (string) Str::uuid(),
            'selling_org_uuid' => (string) Str::uuid(),
            'body' => 'Bu ürün kaç beden büyük geliyor?',
            'status' => QuestionStatus::Pending,
        ];
    }

    public function pending(): self
    {
        return $this->state(fn (): array => [
            'status' => QuestionStatus::Pending,
            'answer_body' => null,
            'answered_at' => null,
            'answered_by' => null,
        ]);
    }

    public function answered(string $answer = 'Normal kalıp, kendi bedeninizi alabilirsiniz.'): self
    {
        return $this->state(fn (): array => [
            'status' => QuestionStatus::Answered,
            'answer_body' => $answer,
            'answered_at' => now(),
            'answered_by' => 1,
        ]);
    }

    /**
     * An admin took it down. Deliberately ORTHOGONAL to the status states — a
     * pending question and an answered one can both be hidden, which is why
     * hiding is a flag rather than a third case.
     */
    public function hidden(): self
    {
        return $this->state(fn (): array => [
            'hidden_at' => now(),
            'hidden_by' => 1,
            'hidden_reason' => 'Küfür içeriyor',
        ]);
    }

    public function forProduct(string $productUuid): self
    {
        return $this->state(fn (): array => ['product_uuid' => $productUuid]);
    }

    public function forStore(string $storeUuid): self
    {
        return $this->state(fn (): array => ['store_uuid' => $storeUuid]);
    }

    public function forCustomer(int $customerId, string $customerUuid): self
    {
        return $this->state(fn (): array => [
            'customer_id' => $customerId,
            'customer_uuid' => $customerUuid,
        ]);
    }
}
