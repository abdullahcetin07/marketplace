<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A user marked a device as trusted.
 *
 * Trusting a device lets it skip the 2FA challenge, so it is security-relevant:
 * the Activity module records it on the owner's timeline, where a trust the
 * user did not grant is a visible warning.
 */
final class DeviceTrusted extends BaseEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly string $userUuid,
        public readonly int $deviceId,
        public readonly string $deviceLabel,
    ) {
        parent::__construct();
    }
}
