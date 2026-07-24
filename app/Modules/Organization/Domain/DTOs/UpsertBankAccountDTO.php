<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * Set or replace an organization's payout bank account.
 *
 * The IBAN arrives in plaintext over the (TLS) request and is encrypted the
 * moment it lands on the model. Currency is an ISO code; the action resolves it.
 */
final class UpsertBankAccountDTO extends BaseDTO
{
    public function __construct(
        public readonly int $organizationId,
        public readonly string $accountHolder,
        public readonly string $iban,
        public readonly ?string $bankName,
        public readonly string $currencyCode,
    ) {}

    /**
     * Normalise for storage/comparison: IBANs are written with spaces and in
     * mixed case, but the canonical form is unspaced and upper.
     */
    public function normalisedIban(): string
    {
        return mb_strtoupper(str_replace(' ', '', trim($this->iban)));
    }
}
