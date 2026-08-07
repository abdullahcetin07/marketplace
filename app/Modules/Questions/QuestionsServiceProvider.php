<?php

declare(strict_types=1);

namespace App\Modules\Questions;

use App\Modules\Questions\Domain\Contracts\QuestionRepositoryContract;
use App\Modules\Questions\Infrastructure\Repositories\QuestionRepository;
use App\Shared\Enums\UserType;
use App\Shared\Support\PermissionRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Questions module wiring — "Satıcıya Sor".
 *
 * **THE MIRROR-IMAGE OF REVIEWS, AND WORTH READING AS ONE.** A review reports an
 * experience, so it is gated on a delivered purchase and pre-moderated. A question
 * is asked to DECIDE whether to buy, so it has no purchase gate at all — and it is
 * published by the SELLER'S ANSWER rather than by a moderator. Same shape, opposite
 * mechanics, on purpose (ADR-070).
 *
 * **THE TARGET IS SERVER-DERIVED AND SNAPSHOTTED.** A question is aimed at the
 * buy-box winner at the moment it is asked, read from `OfferQueryContract` and
 * frozen onto the row — the client sends `{product, body}` and no seller, so it
 * cannot aim a question at a shop that is not selling the thing. A later buy-box
 * change never re-aims a past question.
 *
 * **AN ADMIN HIDES; AN ADMIN NEVER ANSWERS** (ADR-071). The platform speaking in a
 * merchant's place would be a promise the merchant did not make, so moderation is
 * one reversible flag and nothing more.
 *
 * IT IMPORTS NO MODULE. Catalog, Offer, Store and Organization are all read through
 * Core contracts; it has no photos and therefore no `HasMedia`, and no money —
 * there is no rating and no price here, so the minor-units rule does not apply.
 *
 * WHAT IT IS NOT: a review, a chat thread, a support ticket, or a place the
 * platform answers for the seller.
 *
 * @see docs/modules/Questions.md
 */
final class QuestionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(QuestionRepositoryContract::class, QuestionRepository::class);

        $this->registerPermissions();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('Modules/Questions/migrations'));
    }

    /**
     * Permissions are DERIVED from a registration, never hand-listed.
     *
     * **`question` IS READ BY TWO PANELS, AND THAT IS THE UNUSUAL PART.** Reviews
     * registers its resource for admins alone; a question is answered by the
     * merchant it was aimed at, so the seller guard needs `view_any`/`view` too —
     * and the seller's own resource scopes them to their stores (ADR-030/071).
     *
     * **TWO ABILITIES, EACH ON EXACTLY ONE GUARD, AND THE SPLIT IS THE MODULE'S
     * CENTRAL RULE.** `question.answer` is the SELLER's and no admin holds it: the
     * platform answering in a merchant's place is a promise the merchant did not
     * make. `question.moderate` is the ADMIN's and no seller holds it: the party a
     * question is aimed at does not get to make it disappear.
     *
     * There is deliberately no `question.create` — asking is a customer action,
     * authorised by being signed in (ADR-070), not a privilege anyone is granted.
     */
    private function registerPermissions(): void
    {
        PermissionRegistry::resource('question', [UserType::Admin, UserType::Seller]);
        PermissionRegistry::ability('question.answer', [UserType::Seller]);
        PermissionRegistry::ability('question.moderate', [UserType::Admin]);
    }
}
