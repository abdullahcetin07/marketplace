<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * The kind of a KYC / legal document.
 *
 * Deliberately generic names (not country-specific): a "trade registry" or
 * "signature circular" exists in many jurisdictions under different names. The
 * country nuance is a label/validation concern, not a new case. `Other` is the
 * escape hatch for anything a jurisdiction requires that these do not name.
 *
 * @see docs/modules/Organization.md §2.4
 */
enum OrganizationDocumentType: string
{
    use HasEnumHelpers;

    case TaxCertificate = 'tax_certificate';
    case TradeRegistry = 'trade_registry';
    case SignatureCircular = 'signature_circular';
    case IdDocument = 'id_document';
    case Other = 'other';
}
