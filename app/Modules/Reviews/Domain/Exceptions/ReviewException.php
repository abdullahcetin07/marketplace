<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Domain\Exceptions;

use App\Core\Domain\Exceptions\BaseException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The ways a review may be refused (ADR-067/068).
 *
 * **NONE OF THESE IS AN INCIDENT.** `$reportable` stays false: a buyer trying to
 * review something they did not buy is the gate doing its job, not a bug, and
 * paging somebody about it would bury the failures that matter.
 *
 * THE MESSAGES ARE TURKISH BECAUSE THE BUYER READS THEM. These reach a
 * storefront form, not a log — the one audience for "bu ürünü değerlendirmek
 * için önce satın almış olmanız gerekiyor" is the person who just tried.
 *
 * @see docs/modules/Reviews.md §8
 */
final class ReviewException extends BaseException
{
    /**
     * No delivered, unreviewed line of this product belongs to this buyer.
     *
     * **ONE ANSWER FOR FOUR DIFFERENT REFUSALS** — never bought it, bought it but
     * it has not arrived, bought it and already reviewed it, or named somebody
     * else's line entirely. Telling them apart would let anyone map the
     * platform's order lines by watching which message came back, and the buyer
     * can act on none of the distinctions anyway.
     */
    public static function notEligible(): self
    {
        return self::make(__('reviews.errors.not_eligible'))
            ->withContext(['reason' => 'not_eligible'])
            ->withStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * A moderator answered a review that had already been answered.
     *
     * A REFUSAL RATHER THAN A NO-OP, because the two verdicts do opposite
     * things: publishing a rejected review would put back what somebody removed,
     * and rejecting a published one would pull a live review without the person
     * clicking knowing they were the second to decide.
     */
    public static function notPending(string $reviewUuid): self
    {
        return self::make(__('reviews.errors.not_pending'))
            ->withContext(['reason' => 'not_pending', 'review' => $reviewUuid])
            ->withStatus(Response::HTTP_CONFLICT);
    }

    /**
     * This purchase already has a review.
     *
     * DISTINCT FROM `notEligible()` ON PURPOSE, and it is the one distinction
     * worth drawing: the buyer DID buy it and the platform knows, so telling
     * them "you have already reviewed this" is actionable — they can go and edit
     * their mind by deleting it. It leaks nothing they do not already know.
     */
    public static function alreadyReviewed(): self
    {
        return self::make(__('reviews.errors.already_reviewed'))
            ->withContext(['reason' => 'already_reviewed'])
            ->withStatus(Response::HTTP_CONFLICT);
    }

    /**
     * No published product answers to this id or slug.
     *
     * THE SAME 404 THE OFFERS ENDPOINT GIVES, deliberately: "no such product",
     * "not published" and "that is a category slug" are one answer, or the
     * differences between them map the catalogue for whoever is probing.
     */
    public static function productNotFound(string $idOrSlug): self
    {
        return self::make(__('reviews.errors.product_not_found'))
            ->withContext(['reason' => 'product_not_found', 'product' => $idOrSlug])
            ->withStatus(Response::HTTP_NOT_FOUND);
    }
}
