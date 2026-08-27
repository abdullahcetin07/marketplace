<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Contracts;

/**
 * The catalogue's free-text search engine, as the rest of Catalog sees it
 * (ADR-090).
 *
 * **A CONTRACT FOR A MODULE-INTERNAL COLLABORATOR, WHICH THIS CODEBASE USUALLY
 * REFUSES TO WRITE** — `PublicProductBrowse` is deliberately a concrete class
 * for exactly that reason. The difference here is that the implementation talks
 * to a process that can be absent, slow or wrong, and the two behaviours that
 * matter (relevance order, and the fallback when there is no engine) have to be
 * provable without one running. An interface is how a test states "the engine
 * ranked these three, in this order" and "there is no engine today".
 *
 * `null` from `rankedUuids()` and `suggest()` means NO ENGINE, never "no hits" —
 * the caller reads it as an instruction to fall back to the Tier-1 fold.
 */
interface ProductSearchContract
{
    /**
     * Product uuids in relevance order, or null when the engine cannot answer.
     *
     * @return array<int, string>|null
     */
    public function rankedUuids(string $query, int $limit = 500): ?array;

    /**
     * Prefix suggestions, or null when the engine cannot answer.
     *
     * @return array{products: array<int, array<string, string>>, brands: array<int, string>, categories: array<int, string>}|null
     */
    public function suggest(string $query, int $products = 6, int $brands = 4, int $categories = 4): ?array;

    /** Whether the engine is reachable right now. */
    public function isAvailable(): bool;

    /** Whether an engine is configured at all — `false` during the rollout. */
    public function enabled(): bool;
}
