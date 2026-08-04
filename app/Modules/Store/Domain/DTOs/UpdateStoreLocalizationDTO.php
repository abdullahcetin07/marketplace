<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * A seller setting a storefront's locale (§6) — its default language, currency
 * and timezone, by Localization row id. PATCH via `present`.
 *
 * The store is born with the platform defaults (§4.3, ADR-035 unaffected); this
 * lets the seller localise it afterward.
 */
final class UpdateStoreLocalizationDTO extends BaseDTO
{
    /**
     * @param array<int, string> $present
     */
    public function __construct(
        public readonly ?int $defaultLanguageId = null,
        public readonly ?int $defaultCurrencyId = null,
        public readonly ?int $timezoneId = null,
        public readonly array $present = [],
    ) {}

    public function has(string $field): bool
    {
        return in_array($field, $this->present, true);
    }
}
