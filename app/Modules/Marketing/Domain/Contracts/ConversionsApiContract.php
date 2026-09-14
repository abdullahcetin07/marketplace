<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Domain\Contracts;

use App\Modules\Marketing\Domain\DTOs\PurchaseConversionDTO;

/**
 * Sends one conversion to an ad platform's server-side API.
 *
 * An interface so the sender is swappable and mockable — the job depends on this,
 * the Meta HTTP client implements it. A second platform (TikTok, Google Enhanced
 * Conversions) would be another implementation, not a change here.
 */
interface ConversionsApiContract
{
    public function sendPurchase(PurchaseConversionDTO $dto): void;
}
