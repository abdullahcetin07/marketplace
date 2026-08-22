<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Modules\Reviews\Application\Actions\CreateReviewAction;
use App\Modules\Reviews\Application\Actions\DeleteReviewAction;
use App\Modules\Reviews\Application\Actions\PublishReviewAction;
use App\Modules\Reviews\Application\Actions\RejectReviewAction;
use App\Modules\Reviews\Application\Services\ReviewEligibilityService;
use App\Modules\Reviews\Domain\DTOs\ReviewModerationDTO;
use App\Modules\Reviews\Domain\DTOs\SubmitReviewDTO;
use App\Modules\Reviews\Domain\Enums\ReviewStatus;
use App\Modules\Reviews\Domain\Events\ReviewPublished;
use App\Modules\Reviews\Domain\Events\ReviewSubmitted;
use App\Modules\Reviews\Domain\Exceptions\ReviewException;
use App\Modules\Reviews\Domain\Models\Review;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The purchase gate (ADR-067) — the security boundary of this module
|--------------------------------------------------------------------------
|
| **THE CLIENT IS NEVER THE AUTHORITY.** A form request checks that
| `order_line_uuid` looks like a uuid; the storefront's eligibility read is a
| convenience for drawing a form. The action asks the server again, and these
| tests are every way somebody could get past it if it did not:
|
|   FORGED     a line uuid nobody delivered to them
|   OTHERS'    a real line, somebody else's purchase
|   UNSHIPPED  their own purchase, not yet delivered
|   TWICE      a line they already reviewed, including one still pending
|
| Order is FAKED here, deliberately: Reviews may not import it, so the module's
| own tests must exercise it through the Core contract exactly as production
| does. Order's side is covered by `DeliveredPurchaseLinesTest`.
|
*/

/**
 * A stand-in for Order that answers with whatever lines a test hands it.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @param array<int, array<string, mixed>> $lines
 */
function fakeOrderPort(array $lines): void
{
    app()->bind(OrderQueryContract::class, fn (): OrderQueryContract => new class($lines) implements OrderQueryContract
    {
        /** @param array<int, array<string, mixed>> $lines */
        public function __construct(private readonly array $lines) {}

        /** @return array<int, array<string, mixed>> */
        public function deliveredPurchaseLines(string $customerUuid, string $productUuid): array
        {
            return array_values(array_filter(
                $this->lines,
                static fn (array $line): bool => $line['customer_uuid'] === $customerUuid
                    && $line['product_uuid'] === $productUuid,
            ));
        }

        public function orderExists(string $orderUuid): bool
        {
            return false;
        }

        public function orderStatus(string $orderUuid): ?string
        {
            return null;
        }

        /** @return array<int, string> */
        public function ordersForCheckoutGroup(string $checkoutGroupUuid): array
        {
            return [];
        }

        public function checkoutGroupFor(string $orderUuid): ?string
        {
            return null;
        }

        /** @return array{id: int, uuid: string, email: string}|null */
        public function checkoutGroupCustomer(string $checkoutGroupUuid): ?array
        {
            return null;
        }

        /** @return array<int, array<string, mixed>> */
        public function orderLines(string $orderUuid): array
        {
            return [];
        }

        /** @return array<string, string> */
        public function reservationReferencesFor(string $orderUuid): array
        {
            return [];
        }

        /** @return array{selling_org_uuid: string, commission_minor: int|null}|null */
        public function orderSettlement(string $orderUuid): ?array
        {
            return null;
        }

        /** @return array{order_number: string, selling_org_uuid: string, customer_id: int, status: string}|null */
        public function orderFulfilment(string $orderUuid): ?array
        {
            return null;
        }

        /** @return array<int, string> */
        public function paidOrders(?string $sellerOrgUuid = null, int $limit = 500): array
        {
            return [];
        }

        /** @return array{items_total_minor: int, tax_total_minor: int, grand_total_minor: int, currency_code: string}|null */
        public function orderTotals(string $orderUuid): ?array
        {
            return null;
        }

        /*
        | THE INVITATION SWEEP'S READ (ADR-087) IS NOT WHAT THIS FAKE IS ABOUT
        | either. It shares the interface because Order owns the lines both
        | answers read; a stand-in for the DELIVERY gate has nothing to say about
        | who should be emailed tonight.
        |
        | @return array<int, array<string, mixed>>
        */
        public function deliveredLinesForReviewInvitation(CarbonInterface $deliveredBefore, int $limit = 500): array
        {
            return [];
        }

        /*
        | THE RANKING PORTS (ADR-077/078) ARE NOT WHAT THIS FAKE IS ABOUT. They
        | belong to the same interface because Order owns the lines both answers
        | read; a stand-in for the DELIVERY gate has nothing to say about either.
        */
        /** @return array<int, string> */
        public function bestSellingProductUuids(int $limit): array
        {
            return [];
        }

        /** @return array<int, string> */
        public function coPurchasedProductUuids(string $productUuid, int $limit): array
        {
            return [];
        }

        /*
        | THE POINTS READER (ADR-083) IS NOT WHAT THIS FAKE IS ABOUT EITHER. It
        | shares the interface because Order owns what a seller-order was paid;
        | a stand-in for the DELIVERY gate has nothing to say about loyalty.
        */
        /** @return array<int, array{order_uuid: string, customer_uuid: string, paid_minor: int, currency_code: string, delivered_at: string}> */
        public function pointsEligibleSellerOrders(CarbonInterface $asOf): array
        {
            return [];
        }

        public function activeCartTotalFor(string $customerUuid): int
        {
            return 0;
        }
    });
}

/**
 * One delivered line, shaped as the Core port returns them.
 *
 * @return array<string, mixed>
 */
function gateLine(string $customerUuid, string $productUuid, string $lineUuid, string $storeUuid = 'magaza-1'): array
{
    return [
        'customer_uuid' => $customerUuid,
        'product_uuid' => $productUuid,
        'order_line_uuid' => $lineUuid,
        'store_uuid' => $storeUuid,
        'selling_org_uuid' => 'satici-org-1',
        'variant_uuid' => 'varyant-1',
        'variant_label' => 'Mavi / M',
        'product_title' => 'Pamuklu Tişört',
        'purchased_at' => '2026-08-01T10:00:00+03:00',
    ];
}

function submitDto(string $lineUuid, string $productUuid, int $customerId = 7, string $customerUuid = 'musteri'): SubmitReviewDTO
{
    return new SubmitReviewDTO(
        orderLineUuid: $lineUuid,
        rating: 5,
        body: 'Beklediğimden iyi çıktı.',
        productUuid: $productUuid,
        customerId: $customerId,
        customerUuid: $customerUuid,
        authorName: 'Ayşe Y.',
    );
}

beforeEach(function (): void {
    $this->product = (string) Str::uuid();
    $this->line = (string) Str::uuid();
});

it('lets a buyer review a product that was delivered to them', function (): void {
    fakeOrderPort([gateLine('musteri', $this->product, $this->line)]);
    Event::fake([ReviewSubmitted::class]);

    $review = app(CreateReviewAction::class)->run(submitDto($this->line, $this->product));

    /*
     * **BORN INVISIBLE** (ADR-068). The buyer gets a 201 that says
     * `pending_review`, so the UI says "onay bekliyor" rather than
     * congratulating them on a review nobody can read yet.
     */
    expect($review->status)->toBe(ReviewStatus::PendingReview)
        ->and($review->rating)->toBe(5)
        /*
         * **THE SELLER TAG CAME FROM THE LINE, NOT THE REQUEST** (ADR-066). The
         * DTO has no field that could have carried it — which is what makes it
         * impossible for a buyer to attribute their review to a shop they did
         * not buy from.
         */
        ->and($review->store_uuid)->toBe('magaza-1')
        ->and($review->selling_org_uuid)->toBe('satici-org-1')
        ->and($review->variant_uuid)->toBe('varyant-1');

    Event::assertDispatched(ReviewSubmitted::class);
});

it('refuses a line the buyer was never delivered', function (): void {
    // The port knows nothing about this customer at all.
    fakeOrderPort([]);

    expect(fn () => app(CreateReviewAction::class)->run(submitDto($this->line, $this->product)))
        ->toThrow(ReviewException::class);

    expect(Review::query()->count())->toBe(0);
});

it('refuses a forged line uuid for a product they DID buy', function (): void {
    // They hold a genuine delivered line — just not the one they are naming.
    fakeOrderPort([gateLine('musteri', $this->product, (string) Str::uuid())]);

    /*
     * **THE MOST INTERESTING ATTACK, because everything about it looks right**:
     * a real customer, a real product they really bought, and a uuid they made
     * up. Only the line-by-line check catches it, which is why the action asks
     * for the LINE rather than asking "has this person bought this product".
     */
    expect(fn () => app(CreateReviewAction::class)->run(submitDto($this->line, $this->product)))
        ->toThrow(ReviewException::class);

    expect(Review::query()->count())->toBe(0);
});

it('refuses another customer’s delivered line', function (): void {
    fakeOrderPort([gateLine('baskasi', $this->product, $this->line)]);

    expect(fn () => app(CreateReviewAction::class)->run(submitDto($this->line, $this->product)))
        ->toThrow(ReviewException::class);
});

it('refuses a second review of one purchase, pending or published', function (): void {
    fakeOrderPort([gateLine('musteri', $this->product, $this->line)]);

    app(CreateReviewAction::class)->run(submitDto($this->line, $this->product));

    /*
     * **THE FIRST REVIEW IS STILL PENDING AND THE LINE IS ALREADY SPENT.** If
     * eligibility only subtracted PUBLISHED reviews, a buyer could submit again
     * while the first waited for a moderator — and meet the unique index as a
     * 500 rather than this refusal.
     */
    expect(fn () => app(CreateReviewAction::class)->run(submitDto($this->line, $this->product)))
        ->toThrow(ReviewException::class);

    expect(Review::query()->count())->toBe(1);
});

it('offers a repeat buyer a second line after the first is used', function (): void {
    $second = (string) Str::uuid();

    fakeOrderPort([
        gateLine('musteri', $this->product, $this->line),
        gateLine('musteri', $this->product, $second),
    ]);

    $eligibility = app(ReviewEligibilityService::class);

    expect($eligibility->eligibleLines(7, 'musteri', $this->product))->toHaveCount(2);

    app(CreateReviewAction::class)->run(submitDto($this->line, $this->product));

    /*
     * ONE PURCHASE SPENT, ONE LEFT (ADR-067, owner decision). Two orders of the
     * same product are two experiences — which is why the uniqueness sits on the
     * line and eligibility subtracts lines rather than products.
     */
    $remaining = $eligibility->eligibleLines(7, 'musteri', $this->product);

    expect($remaining)->toHaveCount(1)
        ->and($remaining[0]['order_line_uuid'])->toBe($second);

    // And the second review goes through.
    expect(app(CreateReviewAction::class)->run(submitDto($second, $this->product))->exists)->toBeTrue();
});

it('frees the purchase again when the buyer deletes their review', function (): void {
    fakeOrderPort([gateLine('musteri', $this->product, $this->line)]);

    $review = app(CreateReviewAction::class)->run(submitDto($this->line, $this->product));

    expect(app(ReviewEligibilityService::class)->eligibleLines(7, 'musteri', $this->product))->toBe([]);

    app(DeleteReviewAction::class)->run($review);

    /*
     * DELETE-AND-REWRITE IS THE EDIT STORY (Reviews.md §8). A hard delete frees
     * the `order_line_uuid`, so the buyer who posted the wrong thing can post
     * the right thing — which a soft delete's ghost row would have made
     * impossible forever.
     */
    expect(app(ReviewEligibilityService::class)->eligibleLines(7, 'musteri', $this->product))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| The moderator's two verdicts
|--------------------------------------------------------------------------
*/

it('publishes a pending review and stamps who decided it', function (): void {
    Event::fake([ReviewPublished::class]);
    $review = Review::factory()->pending()->create();

    app(PublishReviewAction::class)->run($review, new ReviewModerationDTO(moderatedBy: 3));

    expect($review->fresh()->status)->toBe(ReviewStatus::Published)
        ->and($review->fresh()->moderated_by)->toBe(3)
        ->and($review->fresh()->moderated_at)->not->toBeNull();

    Event::assertDispatched(ReviewPublished::class);
});

it('records the reason when it rejects, and keeps the row', function (): void {
    $review = Review::factory()->pending()->create();

    app(RejectReviewAction::class)->run($review, new ReviewModerationDTO(moderatedBy: 3, reason: 'Ürünle ilgisi yok'));

    /*
     * **THE ROW STAYS.** The buyer must still see it in "değerlendirmelerim" —
     * a submission that vanished gets written again — and its line must stay
     * spent, or the same refused text comes straight back through eligibility.
     */
    expect($review->fresh()->status)->toBe(ReviewStatus::Rejected)
        ->and($review->fresh()->moderation_reason)->toBe('Ürünle ilgisi yok')
        ->and(Review::query()->count())->toBe(1);
});

it('refuses to decide a review somebody has already decided', function (): void {
    $published = Review::factory()->published()->create();
    $rejected = Review::factory()->rejected()->create();

    /*
     * **BOTH DIRECTIONS, because the two verdicts do opposite things.**
     * Publishing a rejected review puts back what a colleague removed; rejecting
     * a published one pulls a live review without the second clicker knowing
     * they were second. On a queue two people are working, that is the likely
     * mistake rather than the exotic one.
     */
    expect(fn () => app(PublishReviewAction::class)->run($rejected, new ReviewModerationDTO(moderatedBy: 3)))
        ->toThrow(ReviewException::class);

    expect(fn () => app(RejectReviewAction::class)->run($published, new ReviewModerationDTO(moderatedBy: 3, reason: 'yok')))
        ->toThrow(ReviewException::class);

    expect($published->fresh()->status)->toBe(ReviewStatus::Published)
        ->and($rejected->fresh()->status)->toBe(ReviewStatus::Rejected);
});
