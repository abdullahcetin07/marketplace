<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * Profile changes — name, phone, locale preferences.
 *
 * NO EMAIL. Email is a credential and half of the `(type, email)` identity key;
 * changing it is a separate two-step workflow, not a profile field. @see the
 * spec §5.4.
 *
 * Every field is nullable and each is paired with a "was it present?" flag,
 * because this drives a PATCH: absent means "leave unchanged", where present
 * with null means "clear it" (for the fields that permit clearing). Locale
 * codes arrive as ISO strings — the client never handles internal ids.
 */
final class UpdateProfileDTO extends BaseDTO
{
    public function __construct(
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $phone = null,
        public readonly ?string $languageCode = null,
        public readonly ?string $countryCode = null,
        public readonly ?string $currencyCode = null,
        public readonly ?string $timezoneName = null,
        /**
         * Field names actually present in the request, so the action can tell
         * "not supplied" from "supplied as null". @see class docblock.
         *
         * @var array<int, string>
         */
        public readonly array $present = [],
    ) {}

    public function has(string $field): bool
    {
        return in_array($field, $this->present, true);
    }
}
