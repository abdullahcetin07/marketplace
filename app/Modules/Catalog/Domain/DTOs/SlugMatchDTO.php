<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;
use App\Modules\Catalog\Domain\Enums\SluggableType;

/**
 * What a slug turned out to point at (ADR-059).
 *
 * IT CARRIES BOTH SLUGS ON PURPOSE. `slug` is what the visitor asked for and
 * `canonicalSlug` is where the thing actually lives; when they differ, the
 * visitor followed a retired alias and the storefront owes them a 301 rather than
 * a rendered page. Returning only the canonical one would make that case
 * indistinguishable from an ordinary hit, and the platform would quietly serve
 * two URLs for one product forever — which is the duplicate-content problem the
 * alias trail exists to avoid.
 *
 * NO MODEL, only a type and an id. The resolver's job is to say WHICH page to
 * render, not to render it; loading the aggregate here would make one endpoint
 * pay for three different eager-load shapes.
 */
final class SlugMatchDTO extends BaseDTO
{
    public function __construct(
        public readonly SluggableType $type,
        /** The public uuid — never the internal id (non-negotiable #7). */
        public readonly string $uuid,
        public readonly string $slug,
        public readonly string $canonicalSlug,
    ) {}

    /**
     * Whether the visitor arrived on a retired alias and should be redirected.
     */
    public function isAlias(): bool
    {
        return $this->slug !== $this->canonicalSlug;
    }
}
