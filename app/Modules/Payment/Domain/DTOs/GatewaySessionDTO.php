<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * What the buyer's browser needs to show the payment form (Payment.md §3).
 *
 * A TOKEN, NOT A URL, because PayTR's iFrame API hands back a token the client
 * embeds — which is exactly the property that keeps card data out of this
 * platform. The card and the 3-D Secure step happen inside the PSP's frame; this
 * side never sees a PAN and has no field that could hold one.
 */
final class GatewaySessionDTO extends BaseDTO
{
    public function __construct(
        public readonly string $token,
        /** The PSP's own id for the session, when it gives one — for support. */
        public readonly ?string $providerReference = null,
    ) {}
}
