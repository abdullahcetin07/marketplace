<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A user proved control of their email address.
 *
 * For a staff account this is a precondition of signing in at all; for a
 * customer it unlocks nothing by itself but is the first trust signal other
 * modules key off — a store cannot be approved for an unverified seller, an
 * order confirmation must reach a real inbox.
 *
 * Fired only on the FIRST verification. A repeat click on an old link is
 * idempotent and silent — re-announcing it would put duplicate entries in
 * every listener's timeline.
 */
final class EmailVerified extends BaseEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly string $userUuid,
        public readonly string $guard,
    ) {
        parent::__construct();
    }
}
