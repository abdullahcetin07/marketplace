<?php

declare(strict_types=1);

namespace App\Modules\Questions\Domain\Exceptions;

use App\Core\Domain\Exceptions\BaseException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The ways a question may be refused (ADR-070/071).
 *
 * NONE OF THESE IS AN INCIDENT. `$reportable` stays false: a product nobody sells
 * is an ordinary state a page can be in, and a double-clicked answer is two
 * colleagues on one queue.
 *
 * THE MESSAGES ARE TURKISH BECAUSE THE SHOPPER READS THEM — these reach a
 * storefront form, not a log.
 */
final class QuestionException extends BaseException
{
    /**
     * Nobody is selling this product, so there is nobody to ask (ADR-070 §4).
     *
     * **A CLEAN REFUSAL RATHER THAN AN ERROR**, because it is a state a product
     * page is legitimately in: everything went out of stock, or every offer was
     * suspended. The shopper is told the truth — there is no seller right now —
     * not that something broke.
     */
    public static function noSeller(): self
    {
        return self::make(__('questions.errors.no_seller'))
            ->withContext(['reason' => 'no_seller'])
            ->withStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Answering a question somebody already answered.
     *
     * A REFUSAL, NOT A SILENT OVERWRITE. Two colleagues share a seller panel, and
     * the second one's answer replacing the first's — with the shopper already
     * notified of the first — is the failure this prevents.
     */
    public static function notPending(string $questionUuid): self
    {
        return self::make(__('questions.errors.not_pending'))
            ->withContext(['reason' => 'not_pending', 'question' => $questionUuid])
            ->withStatus(Response::HTTP_CONFLICT);
    }

    /**
     * No published product answers to this id or slug.
     *
     * THE SAME 404 EVERY STOREFRONT SURFACE GIVES: "no such product", "not
     * published" and "that is a category slug" are one answer, or the differences
     * map the catalogue for whoever is probing.
     */
    public static function productNotFound(string $idOrSlug): self
    {
        return self::make(__('questions.errors.product_not_found'))
            ->withContext(['reason' => 'product_not_found', 'product' => $idOrSlug])
            ->withStatus(Response::HTTP_NOT_FOUND);
    }
}
