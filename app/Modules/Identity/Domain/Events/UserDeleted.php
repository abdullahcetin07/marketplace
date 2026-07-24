<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * An account was deleted.
 *
 * `permanent` distinguishes a soft delete (recoverable, the default) from a
 * force delete. Listeners that purge downstream data — search documents,
 * cached projections — must act only on the permanent case, or a restore
 * leaves the account working but unfindable.
 */
final class UserDeleted extends BaseEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly string $userUuid,
        public readonly bool $permanent = false,
    ) {
        parent::__construct();
    }
}
