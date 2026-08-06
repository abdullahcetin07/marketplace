<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Domain\Models;

use App\Modules\Reviews\Domain\Enums\ReviewStatus;
use App\Shared\Traits\HasMedia;
use App\Shared\Traits\HasUuid;
use Database\Modules\Reviews\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia as HasMediaContract;

/**
 * One buyer's verdict on one delivered purchase (ADR-066/067).
 *
 * **IT IS A SNAPSHOT, THE WAY AN ORDER LINE IS** (ADR-053). The seller tag, the
 * variant and the author's name are copied at creation from the order line the
 * review is bound to, so a later store rename, a re-pricing or a returned parcel
 * never rewrites what a past review says it was bought from. Reviews imports no
 * module and could not re-read them anyway — but the snapshot is the design
 * rather than a consequence of the boundary.
 *
 * **`order_line_uuid` IS THE WHOLE INTEGRITY MODEL**, and it is UNIQUE. One
 * delivered line earns at most one review; a buyer who bought the same product in
 * two orders may write two, because each is a distinct purchase experience
 * (owner decision). Putting the uniqueness on `(customer, product)` would have
 * refused the second — which is why it sits on the line.
 *
 * **`author_name` IS STORED MASKED**, not masked on render. "Abdullah Ç." is what
 * the column holds, so no future surface can accidentally emit a full name by
 * forgetting a formatter, and a buyer who later changes their name does not
 * retroactively re-sign a review they wrote under the old one.
 *
 * **`has_photos` IS A DENORMALISED FLAG AND EARNS IT.** The public list filters on
 * "sadece resimli" and the summary counts it, both on the hot product-page read;
 * asking the media table would be a join on every row of every page. It is
 * written when photos are attached, in the same action, so the two cannot drift
 * apart without somebody bypassing `CreateReviewAction`.
 *
 * NO MONEY ANYWHERE. A `rating` is a small integer between 1 and 5 — the
 * minor-units rule does not apply to this module at all.
 *
 * @property int $id
 * @property string $uuid
 * @property string $product_uuid
 * @property string|null $variant_uuid
 * @property string $order_line_uuid
 * @property int $customer_id
 * @property string $customer_uuid
 * @property string $author_name
 * @property string $store_uuid
 * @property string $selling_org_uuid
 * @property int $rating
 * @property string|null $body
 * @property ReviewStatus $status
 * @property bool $has_photos
 * @property \Carbon\CarbonImmutable|null $moderated_at
 * @property int|null $moderated_by
 * @property string|null $moderation_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @see docs/modules/Reviews.md §3
 */
final class Review extends Model implements HasMediaContract
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    use HasMedia;
    use HasUuid;

    protected $table = 'reviews';

    protected $fillable = [
        'product_uuid',
        'variant_uuid',
        'order_line_uuid',
        'customer_id',
        'customer_uuid',
        'author_name',
        'store_uuid',
        'selling_org_uuid',
        'rating',
        'body',
        'status',
        'has_photos',
        'moderated_at',
        'moderated_by',
        'moderation_reason',
    ];

    /**
     * The only reviews a stranger may read.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ReviewStatus::Published);
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

    public function isPublished(): bool
    {
        return $this->status->isPublished();
    }

    public function awaitsModeration(): bool
    {
        return $this->status->awaitsModeration();
    }

    protected static function newFactory(): ReviewFactory
    {
        return ReviewFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'rating' => 'integer',
            'has_photos' => 'boolean',
            'status' => ReviewStatus::class,
            // IMMUTABLE, because a moderation stamp is a fact about a past
            // decision: nothing should be able to nudge it by mutating the
            // instance it was read into.
            'moderated_at' => 'immutable_datetime',
            'moderated_by' => 'integer',
        ];
    }
}
