<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * A Category Manager adding a node to the taxonomy (ADR-038).
 *
 * `name` is a locale => text map (`['tr' => 'Giyim', 'en' => 'Clothing']`)
 * rather than one string per locale, so adding a catalog locale does not change
 * every DTO signature — only the columns and the config (§13.5).
 *
 * `parentUuid` rather than an id: the internal id never leaves the application
 * (non-negotiable #7). Null means a root node.
 *
 * `path` and `depth` are absent by design — they are DERIVED from the parent by
 * the action, never supplied. Letting a caller pass a path would let it pass a
 * wrong one.
 */
final class CreateCategoryDTO extends BaseDTO
{
    /**
     * @param array<string, string|null> $name
     */
    public function __construct(
        public readonly array $name,
        public readonly ?string $parentUuid = null,
        public readonly ?string $slug = null,
        public readonly bool $isActive = true,
        /**
         * ADR-047 — products attach only where the Category Manager says so.
         * Defaults FALSE: a new node is a container until it is opened, because
         * the opposite default would re-create the leaf rule's problem in
         * reverse — every new container silently selling.
         */
        public readonly bool $acceptsProducts = false,
        public readonly ?int $position = null,
    ) {}
}
