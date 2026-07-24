<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * Company verification data submitted by a seller.
 *
 * The universal fields are typed; jurisdiction-specific identifiers ride in
 * `metadata` (e.g. `mersis`, `tax_office`) so the DTO does not hardcode any one
 * country. Validation of what a given country requires happens at the request
 * boundary, not here.
 */
final class SubmitKycDTO extends BaseDTO
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly int $organizationId,
        public readonly ?string $taxNumber = null,
        public readonly ?string $registrationNumber = null,
        public readonly ?string $authorizedPersonName = null,
        public readonly ?string $authorizedPersonNationalId = null,
        public readonly array $metadata = [],
    ) {}
}
