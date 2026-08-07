<?php

declare(strict_types=1);

namespace App\Modules\Questions\Infrastructure\Repositories;

use App\Modules\Questions\Domain\Contracts\QuestionRepositoryContract;
use App\Modules\Questions\Domain\DTOs\QuestionListFilterDTO;
use App\Modules\Questions\Domain\Models\Question;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Questions' own table, read the three ways its surfaces need it.
 *
 * **THE VISIBILITY RULE IS APPLIED HERE, NOT ASKED FOR.** `publicForProduct()`
 * calls the model's `public()` scope itself and no method takes a status — a
 * caller cannot request pending questions because none of them offers the choice,
 * rather than because it is trusted not to. That is the same discipline Reviews'
 * repository keeps, and it matters more here: an unanswered question is private
 * to three people, and leaking one publishes a shopper's words before the
 * merchant they were aimed at has seen them.
 *
 * **THERE IS NO SUMMARY, and its absence is the module's shape.** A question has
 * no rating, so a product page shows the Q&A and nothing aggregated — no average
 * to compute on read, no counter anybody could be tempted to denormalise.
 *
 * @see App\Modules\Questions\Domain\Contracts\QuestionRepositoryContract
 */
final class QuestionRepository implements QuestionRepositoryContract
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): Question
    {
        return Question::query()->create($attributes);
    }

    public function findByUuid(string $uuid): ?Question
    {
        return Question::query()->where('uuid', $uuid)->first();
    }

    /**
     * @return LengthAwarePaginator<int, Question>
     */
    public function publicForProduct(string $productUuid, QuestionListFilterDTO $filter): LengthAwarePaginator
    {
        return Question::query()
            ->public()
            ->forProduct($productUuid)
            // "Bu satıcıya sorulanlar" — the target tag, the same shape Reviews'
            // seller filter has: one product page carries every seller's Q&A.
            ->when($filter->sellerStoreUuid !== null, fn ($q) => $q->forStore((string) $filter->sellerStoreUuid))
            // NEWEST FIRST, ALWAYS. There are no votes, so "most helpful" is not
            // an option this module could offer (§11).
            ->orderByDesc('id')
            ->paginate(perPage: $filter->perPage, page: $filter->page);
    }

    /**
     * @return Collection<int, Question>
     */
    public function forCustomer(int $customerId): Collection
    {
        /*
        | EVERY STATUS, AND HIDDEN ONES TOO. A shopper who cannot see their own
        | pending question asks it again believing it was lost. A hidden one is
        | the harder call and lands the same way: it is still THEIR question, and
        | making it vanish from their own list would be the platform editing
        | somebody's history without telling them.
        */
        return Question::query()
            ->where('customer_id', $customerId)
            ->orderByDesc('id')
            ->get();
    }

    public function delete(Question $question): void
    {
        // HARD (§8). Deleting an answered question removes the seller's answer
        // with it, which is correct: the answer only ever existed as a reply to
        // that question, and leaving it orphaned would publish half a exchange.
        $question->delete();
    }
}
