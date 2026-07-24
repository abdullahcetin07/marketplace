<?php

declare(strict_types=1);

namespace App\Modules\Localization\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A user switched their display currency.
 *
 * Note this is a *display* preference. It does not re-price anything already
 * committed — an order settled in TRY stays in TRY. Consumers that convert for
 * display must check Currency::hasFreshRate() first.
 */
final class CurrencyChanged extends BaseEvent
{
    public function __construct(
        public readonly string $fromCode,
        public readonly string $toCode,
        public readonly ?int $userId = null,
    ) {
        parent::__construct();
    }
}
