<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Reviews\Application\Services\ReviewEligibilityService;
use App\Modules\Reviews\Domain\Contracts\ReviewRepositoryContract;
use App\Modules\Reviews\Domain\DTOs\SubmitReviewDTO;
use App\Modules\Reviews\Domain\Enums\ReviewStatus;
use App\Modules\Reviews\Domain\Events\ReviewSubmitted;
use App\Modules\Reviews\Domain\Exceptions\ReviewException;
use App\Modules\Reviews\Domain\Models\Review;
use Illuminate\Http\UploadedFile;

/**
 * A buyer writes one; nobody can see it yet (ADR-067/068).
 *
 * **THE GATE IS CLOSED HERE, NOT IN THE REQUEST**, and that is the single most
 * important line in this class. A form request validates SHAPE — that
 * `order_line_uuid` looks like a uuid — and the storefront's eligibility read is
 * a convenience for drawing a form. Neither is authority. This action asks the
 * eligibility service again, on the server, for this customer, and refuses
 * anything it does not get back.
 *
 * **THE SELLER TAG IS COPIED FROM THE LINE, NEVER FROM INPUT** (ADR-066). That is
 * why the service returns the LINE rather than a boolean: the store, the selling
 * org and the variant are read off the purchase the platform verified, so a buyer
 * cannot attribute their review to a shop they did not buy from and a seller
 * cannot be damned by a review of somebody else's order. `SubmitReviewDTO` has no
 * field that could carry any of them.
 *
 * **IT IS BORN `PendingReview`.** Nothing this action writes is visible to anyone
 * but its author until a moderator publishes it (ADR-068). The 201 the buyer gets
 * back says `pending_review` so the UI says "onay bekliyor" rather than
 * congratulating them on a review nobody can read.
 *
 * **THE PHOTOS ARE PART OF THE SAME UNIT.** They are attached inside the
 * transaction and `has_photos` is set in the same write, so the denormalised flag
 * the product page filters on cannot drift from the media that justifies it. A
 * review with an unacceptable photo is rejected whole — there is no per-photo
 * moderation (ADR-068, Reviews.md §4).
 *
 * @see docs/modules/Reviews.md §8
 */
final class CreateReviewAction extends BaseAction
{
    private ?ReviewSubmitted $submitted = null;

    public function __construct(
        private readonly ReviewEligibilityService $eligibility,
        private readonly ReviewRepositoryContract $reviews,
    ) {}

    /**
     * `handle(SubmitReviewDTO $dto, array<int, UploadedFile> $photos = [])` —
     * variadic because `BaseAction` is, and unpacked immediately below.
     */
    public function handle(mixed ...$arguments): Review
    {
        /** @var SubmitReviewDTO $dto */
        $dto = $arguments[0];
        /** @var array<int, UploadedFile> $photos */
        $photos = $arguments[1] ?? [];

        $line = $this->eligibility->eligibleLine(
            $dto->customerId,
            $dto->customerUuid,
            $dto->productUuid,
            $dto->orderLineUuid,
        );

        if ($line === null) {
            /*
            | NEVER BOUGHT IT, NOT DELIVERED YET, ALREADY REVIEWED, OR NAMED
            | SOMEBODY ELSE'S LINE — one answer for all four. The buyer can act
            | on none of the distinctions, and telling them apart would let
            | anyone map the platform's order lines by watching which message
            | came back.
            */
            throw ReviewException::notEligible();
        }

        $review = $this->reviews->create([
            'product_uuid' => $dto->productUuid,
            'order_line_uuid' => $dto->orderLineUuid,
            'customer_id' => $dto->customerId,
            'customer_uuid' => $dto->customerUuid,
            'author_name' => $dto->authorName,
            'rating' => $dto->rating,
            'body' => $dto->body,
            // AUTHORITATIVE, FROM THE VERIFIED LINE (ADR-066) — not from the DTO,
            // which deliberately cannot carry them.
            'variant_uuid' => $line['variant_uuid'],
            'store_uuid' => $line['store_uuid'],
            'selling_org_uuid' => $line['selling_org_uuid'],
            'status' => ReviewStatus::PendingReview,
            'has_photos' => $photos !== [],
        ]);

        foreach ($photos as $photo) {
            $review->addMedia($photo)->toMediaCollection('images');
        }

        $this->submitted = new ReviewSubmitted(
            reviewId: (int) $review->getKey(),
            reviewUuid: $review->uuid,
            productUuid: $review->product_uuid,
            customerId: $review->customer_id,
        );

        return $review;
    }

    /**
     * Dispatched AFTER COMMIT — a "N değerlendirme onay bekliyor" listener must
     * not count a review a later failure rolls back. No such listener ships in
     * v1 (Reviews.md §11); the event fires now so that one is a new class rather
     * than a change to this action.
     */
    protected function after(mixed $result, mixed ...$arguments): void
    {
        if ($this->submitted !== null) {
            event($this->submitted);
        }
    }
}
