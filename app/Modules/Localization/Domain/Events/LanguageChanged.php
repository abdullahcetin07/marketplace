<?php

declare(strict_types=1);

namespace App\Modules\Localization\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A user switched the language they are browsing in.
 *
 * Carries codes rather than model instances: the event is serialised into the
 * audit log and onto the queue, and a language row's id is meaningless to a
 * consumer reading the log six months later.
 */
final class LanguageChanged extends BaseEvent
{
    public function __construct(
        public readonly string $fromCode,
        public readonly string $toCode,
        public readonly ?int $userId = null,
    ) {
        parent::__construct();
    }
}
