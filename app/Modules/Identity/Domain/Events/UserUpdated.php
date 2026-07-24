<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * An account's attributes changed.
 *
 * `changed` holds attribute names ONLY, never values — the values are already
 * captured with full before/after detail by the Audit module, and duplicating
 * them here would put personal data into a second retention regime with
 * different rules.
 */
final class UserUpdated extends BaseEvent
{
    /**
     * @param  array<int, string>  $changed
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $userUuid,
        public readonly array $changed,
    ) {
        parent::__construct();
    }

    public function touched(string $attribute): bool
    {
        return in_array($attribute, $this->changed, true);
    }
}
