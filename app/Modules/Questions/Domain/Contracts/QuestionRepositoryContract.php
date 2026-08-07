<?php

declare(strict_types=1);

namespace App\Modules\Questions\Domain\Contracts;

use App\Modules\Questions\Domain\DTOs\QuestionListFilterDTO;
use App\Modules\Questions\Domain\Models\Question;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * How this module reaches its own table.
 *
 * MODULE-INTERNAL, NOT A CORE PORT. Nothing outside Questions resolves it: it is
 * the persistence seam every module keeps between its actions and Eloquent. The
 * questions another context might ask are HTTP endpoints (§8).
 *
 * **THE PUBLIC READ APPLIES ITS OWN VISIBILITY, and takes no status parameter.**
 * A caller cannot ask for pending questions because no method offers the choice —
 * the alternative is a flag somebody eventually passes wrong, publishing a
 * question the seller has not answered.
 *
 * **THERE IS NO SUMMARY METHOD, unlike Reviews'**, and the absence is the point:
 * a question has no rating to roll up. A product page shows the Q&A and nothing
 * aggregated, so there is no arithmetic here to keep honest.
 */
interface QuestionRepositoryContract
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): Question;

    public function findByUuid(string $uuid): ?Question;

    /**
     * Answered, un-hidden questions for a product — newest first, paginated.
     *
     * @return LengthAwarePaginator<int, Question>
     */
    public function publicForProduct(string $productUuid, QuestionListFilterDTO $filter): LengthAwarePaginator;

    /**
     * One asker's own questions, every status, newest first.
     *
     * ALL STATUSES ON PURPOSE (§8): a shopper must see their own PENDING question
     * or they will ask it again believing it was lost — the same reason Reviews
     * shows an author their unpublished review.
     *
     * @return Collection<int, Question>
     */
    public function forCustomer(int $customerId): Collection;

    /**
     * A HARD delete. A question is not an audit record — the append-only rule
     * belongs to Audit and Activity — and the asker may remove their own whether
     * or not it was answered (§8). Deleting an answered one removes the seller's
     * answer with it, which is correct: the answer only ever existed as a reply
     * to that question.
     */
    public function delete(Question $question): void;
}
