<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * The seller's variant-axis selection, to be multiplied out (§13.4, ruled).
 *
 * `selection` maps an attribute uuid to the value uuids chosen on that axis:
 *
 *     ['renk-uuid' => ['kirmizi-uuid', 'mavi-uuid'],
 *      'beden-uuid' => ['m-uuid', 'l-uuid']]        →  4 variants
 *
 * `exclude` prunes specific combinations from the cartesian result — the
 * "prunable" half of the ruling. Each entry is a `combination_key` as
 * `ProductVariant::combinationKeyFor()` produces it, so the caller cannot
 * express an exclusion in a format the matcher will not recognise.
 *
 * An empty selection is valid: it generates the single default variant of a
 * product with no axes (ADR-039 — never a special case).
 */
final class GenerateVariantsDTO extends BaseDTO
{
    /**
     * @param array<string, array<int, string>> $selection
     * @param array<int, string> $exclude
     */
    public function __construct(
        public readonly array $selection = [],
        public readonly array $exclude = [],
        public readonly bool $pruneMissing = false,
    ) {}
}
