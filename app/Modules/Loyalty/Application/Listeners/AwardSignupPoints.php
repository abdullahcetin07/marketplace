<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Listeners;

use App\Modules\Loyalty\Application\Actions\GrantPointsAction;
use App\Modules\Loyalty\Domain\DTOs\GrantPointsDTO;
use App\Modules\Loyalty\Domain\Enums\LoyaltyPointSource;

/**
 * Joining earns points, once (ADR-081).
 *
 * **BY CLASS-STRING, READING THE PAYLOAD BY PROPERTY.** Identity's
 * registration event is never named as a type here — Loyalty imports no module and
 * `LayeringTest` fails the build on one. The provider subscribes by string; this
 * handler reads `userUuid` and `type` off an object it only knows the shape of.
 *
 * **CUSTOMERS ONLY.** `UserCreated` fires for admins and sellers too, and a
 * platform employee accruing shopping points is a payout waiting to be explained.
 * The actor type is checked by VALUE rather than against the enum's class, for the
 * same import reason.
 *
 * The customer uuid is the idempotency key, so a registration event replayed by a
 * queue retry credits nothing twice.
 */
final class AwardSignupPoints
{
    public function __construct(private readonly GrantPointsAction $grant) {}

    public function handle(object $event): void
    {
        $customerUuid = $event->userUuid ?? null;
        $type = $event->type ?? null;

        if (! is_string($customerUuid)) {
            return;
        }

        // The enum instance's ->value, without naming the enum.
        $typeValue = is_object($type) && property_exists($type, 'value') ? $type->value : $type;

        if ($typeValue !== 'customer') {
            return;
        }

        $this->grant->run(new GrantPointsDTO(
            customerUuid: $customerUuid,
            points: (int) settings('loyalty.earn.signup', 100),
            source: LoyaltyPointSource::Signup,
            sourceUuid: $customerUuid,
            meta: ['rule' => 'signup'],
        ));
    }
}
