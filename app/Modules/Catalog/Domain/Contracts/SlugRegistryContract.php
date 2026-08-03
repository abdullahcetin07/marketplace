<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Contracts;

use App\Modules\Catalog\Domain\DTOs\SlugMatchDTO;
use App\Modules\Catalog\Domain\Enums\SluggableType;

/**
 * The one owner of every public storefront address (ADR-059).
 *
 * THE FLAT URL SCHEME IS WHY THIS EXISTS. Product, category and brand all live at
 * the root, so "is this slug free?" is not a question any one of their
 * repositories can answer — each only knows its own table. This does, plus the
 * reserved-word list the storefront's own pages occupy.
 *
 * TWO VERBS, AND THE SPLIT MATTERS. `issue()` is pure — it works out a free slug
 * and writes nothing — so an action can compute a slug before it has a row to
 * attach it to (every create does exactly that). `register()` is the write, and
 * it is idempotent: calling it with the slug an entity already has is a no-op,
 * which is what lets every update path call it unconditionally.
 *
 * STABILITY IS THE REGISTRY'S RULE, NOT THE CALLER'S. `register()` never mutates
 * an existing canonical row; handed a different slug it writes a NEW canonical one
 * and demotes the old to an alias. So a rename cannot break a link by accident —
 * a caller would have to ask for a new slug deliberately.
 *
 * @see App\Modules\Catalog\Infrastructure\Registries\SlugRegistry
 * @see docs/Architecture_Decision_Record.md ADR-059
 */
interface SlugRegistryContract
{
    /**
     * A slug that is free across every kind and is not a reserved word.
     *
     * Writes nothing. `$keepFor` exempts an entity's own existing slug from the
     * collision check, so re-issuing for something that already holds the slug
     * returns it unchanged instead of appending "-2".
     */
    public function issue(string $requested, SluggableType $type, ?int $keepFor = null): string;

    /**
     * Make `$slug` the canonical address of this entity.
     *
     * Idempotent. If the entity already has a DIFFERENT canonical slug, that row
     * is demoted to an alias rather than deleted — an inbound link to the old
     * address must keep working (ADR-059).
     */
    public function register(string $slug, SluggableType $type, int $id): void;

    /**
     * What this slug points at, canonical or alias, or null when nothing does.
     */
    public function resolve(string $slug): ?SlugMatchDTO;

    /**
     * Forget every slug an entity owns — for a hard delete, where leaving an
     * alias behind would resolve to a row that is gone.
     */
    public function forget(SluggableType $type, int $id): void;

    /**
     * Whether a slug is one of the storefront's own page names.
     *
     * Exposed so a form can say "that name is not available" instead of silently
     * handing back a suffixed slug the seller did not ask for.
     */
    public function isReserved(string $slug): bool;
}
