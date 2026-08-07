<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Seller;
use App\Modules\Reviews\Domain\Enums\ReviewStatus;
use App\Modules\Reviews\Domain\Models\Review;
use App\Modules\Reviews\Presentation\Filament\Resources\ReviewModerationResource;

/*
|--------------------------------------------------------------------------
| Who may decide (ADR-068)
|--------------------------------------------------------------------------
|
| **THE SELLER HAS NO LEVER OVER A REVIEW.** Not to approve, not to reject, not
| to hide, not even to read the queue. That is the module's central rule and the
| reason this file exists — a merchant able to suppress criticism of their own
| goods makes every remaining review worthless.
|
| Admin and Editor moderate; Super Admin bypasses every policy already. Editor is
| the platform's CONTENT role, and review text and photographs are content.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

it('lets an admin decide, and the published review reaches the public page', function (): void {
    $admin = Admin::factory()->create();
    $admin->assignRole(config('marketplace.roles.admin'));

    $review = Review::factory()->pending()->create();

    $this->actingAs($admin, 'admin');

    expect($admin->can('moderate', Review::class))->toBeTrue()
        ->and(ReviewModerationResource::canViewAny())->toBeTrue()
        // Read and decide, never edit: fixing a review would be rewriting an
        // opinion under somebody else's name.
        ->and(ReviewModerationResource::canCreate())->toBeFalse()
        ->and(ReviewModerationResource::canEdit($review))->toBeFalse()
        ->and(ReviewModerationResource::canDelete($review))->toBeFalse();
});

it('lets an editor moderate, because a review is content', function (): void {
    $editor = Admin::factory()->create();
    $editor->assignRole(config('marketplace.roles.editor'));

    $this->actingAs($editor, 'admin');

    /*
     * THE EXCEPTION TO "THE EDITOR DOES NOT MODERATE" (ADR-068). That rule is
     * about the CATALOGUE, where a rejection sends a seller's listing back and
     * belongs with the Category Manager who owns the taxonomy. A review is
     * neither taxonomy nor a listing — it is user text and photographs, which is
     * what the content role reads all day.
     */
    expect($editor->can('moderate', Review::class))->toBeTrue()
        ->and(ReviewModerationResource::canViewAny())->toBeTrue();
});

it('keeps every seller away from the queue and the verdict', function (): void {
    $seller = Seller::factory()->create();

    $this->actingAs($seller, 'seller');

    /*
     * **THE PARTY A REVIEW JUDGES GETS NO LEVER OVER IT.** The resource is
     * registered in the ADMIN panel only, and the ability is denied outright
     * rather than merely unheld — a seller who was somehow granted
     * `review.moderate` would still be refused, because `ReviewPolicy::moderate()`
     * checks the actor TYPE first.
     */
    expect($seller->can('moderate', Review::class))->toBeFalse()
        ->and(ReviewModerationResource::canViewAny())->toBeFalse();
});

it('keeps a customer away from it too', function (): void {
    $customer = Customer::factory()->create();

    $this->actingAs($customer, 'customer');

    // A buyer may write and delete their own review; they may not decide
    // anybody's, including their own.
    expect($customer->can('moderate', Review::class))->toBeFalse()
        ->and(ReviewModerationResource::canViewAny())->toBeFalse();
});

it('publishes through the panel action and the review becomes public', function (): void {
    $admin = Admin::factory()->create();
    $admin->assignRole(config('marketplace.roles.admin'));
    $this->actingAs($admin, 'admin');

    $review = Review::factory()->pending()->create();

    app(App\Modules\Reviews\Application\Actions\PublishReviewAction::class)->run(
        $review,
        new App\Modules\Reviews\Domain\DTOs\ReviewModerationDTO(moderatedBy: (int) $admin->getKey()),
    );

    expect($review->fresh()->status)->toBe(ReviewStatus::Published)
        ->and($review->fresh()->moderated_by)->toBe((int) $admin->getKey());

    /*
     * AND THE PUBLIC SURFACE AGREES. The queue is not a private status field —
     * publishing is the moment the review starts counting toward a product's
     * average and appearing to strangers.
     */
    expect(app(App\Modules\Reviews\Domain\Contracts\ReviewRepositoryContract::class)
        ->summaryForProduct($review->product_uuid)['count'])->toBe(1);
});

it('counts only what is waiting on the sidebar badge', function (): void {
    Review::factory()->pending()->count(2)->create();
    Review::factory()->published()->create();
    Review::factory()->rejected()->create();

    // A badge counting every review ever written settles at a number nobody
    // reads; this one is zero when the queue is clear.
    expect(ReviewModerationResource::getNavigationBadge())->toBe('2');

    Review::query()->where('status', ReviewStatus::PendingReview)->delete();

    expect(ReviewModerationResource::getNavigationBadge())->toBeNull();
});
