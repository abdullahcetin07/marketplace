<?php

declare(strict_types=1);

namespace App\Modules\Questions\Domain\Models;

use App\Modules\Questions\Domain\Enums\QuestionStatus;
use App\Shared\Traits\HasUuid;
use Database\Modules\Questions\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One shopper's question, and the seller's answer to it (ADR-070/071).
 *
 * **THE TARGET IS A SNAPSHOT, LIKE A REVIEW'S SELLER TAG.** `store_uuid` and
 * `selling_org_uuid` are copied from the featured offer at ask time, so a later
 * buy-box change never re-aims a past question — the merchant who was asked stays
 * the merchant who was asked, and the answer on the page stays attributable to
 * whoever gave it.
 *
 * **VISIBILITY IS TWO COLUMNS, NEVER ONE.** `Answered` alone is not public and
 * `hidden_at IS NULL` alone is not either: a pending question is private to the
 * target seller, the admin and the asker, and an answered one an admin has hidden
 * is gone from every surface. `isPublic()` is the one place that conjunction
 * lives, so no query can accidentally publish half of it.
 *
 * **HIDING IS REVERSIBLE AND STATUS IS NOT**, which is why they are different
 * mechanisms. Un-hiding restores whatever the question already was; there is no
 * "was it answered before it was hidden?" to reconstruct.
 *
 * **`asker_name` IS STORED MASKED** ("Abdullah Ç."), exactly as a review's author
 * is — so no future surface leaks a full name by forgetting a formatter, and a
 * shopper who later changes their name does not retroactively re-sign an old
 * question.
 *
 * NO MEDIA AND NO MONEY. A question is text (§11); there is no rating and no
 * price, so the minor-units rule does not apply to this class at all.
 *
 * @property int $id
 * @property string $uuid
 * @property string $product_uuid
 * @property int $customer_id
 * @property string $customer_uuid
 * @property string $asker_name
 * @property string $store_uuid
 * @property string $selling_org_uuid
 * @property string $body
 * @property QuestionStatus $status
 * @property string|null $answer_body
 * @property \Carbon\CarbonImmutable|null $answered_at
 * @property int|null $answered_by
 * @property \Carbon\CarbonImmutable|null $hidden_at
 * @property int|null $hidden_by
 * @property string|null $hidden_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @see docs/modules/Questions.md §5
 */
final class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use HasFactory;

    use HasUuid;

    protected $table = 'questions';

    protected $fillable = [
        'product_uuid',
        'customer_id',
        'customer_uuid',
        'asker_name',
        'store_uuid',
        'selling_org_uuid',
        'body',
        'status',
        'answer_body',
        'answered_at',
        'answered_by',
        'hidden_at',
        'hidden_by',
        'hidden_reason',
    ];

    /**
     * The only questions a stranger may read: answered AND not hidden.
     *
     * BOTH HALVES, IN ONE SCOPE. Splitting them would mean every caller
     * remembering the second, and the one that forgot would publish either an
     * unanswered question or one an admin took down.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('status', QuestionStatus::Answered)->whereNull('hidden_at');
    }

    /**
     * What the target seller may see — everything aimed at them EXCEPT what an
     * admin has hidden.
     *
     * A HIDDEN QUESTION DISAPPEARS FROM THE SELLER TOO, not only from the public
     * page. An admin hides abuse, and leaving it in the merchant's queue would
     * make them read it anyway — which is most of what the hide was for.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeVisibleToSeller(Builder $query): Builder
    {
        return $query->whereNull('hidden_at');
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeForProduct(Builder $query, string $productUuid): Builder
    {
        return $query->where('product_uuid', $productUuid);
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeForStore(Builder $query, string $storeUuid): Builder
    {
        return $query->where('store_uuid', $storeUuid);
    }

    /**
     * Answered, and not taken down. @see `scopePublic()` — the same conjunction,
     * asked of one row.
     */
    public function isPublic(): bool
    {
        return $this->status->isAnswered() && $this->hidden_at === null;
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    protected static function newFactory(): QuestionFactory
    {
        return QuestionFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'status' => QuestionStatus::class,
            // IMMUTABLE: both are facts about a past decision, and nothing should
            // be able to nudge one by mutating the instance it was read into.
            'answered_at' => 'immutable_datetime',
            'answered_by' => 'integer',
            'hidden_at' => 'immutable_datetime',
            'hidden_by' => 'integer',
        ];
    }
}
