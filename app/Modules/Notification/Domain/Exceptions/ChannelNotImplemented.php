<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\Exceptions;

use App\Core\Domain\Exceptions\BaseException;
use App\Shared\Enums\NotificationType;

/**
 * A notification was routed to a channel that has no provider yet.
 *
 * Sprint 1 ships the notification INFRASTRUCTURE — channels, queues, the
 * preference model — but deliberately no SMS or push provider, because
 * choosing one is a commercial decision that has not been made.
 *
 * REPORTABLE, unlike most domain exceptions. A notification silently vanishing
 * is worse than one that fails loudly: the user is waiting for a message
 * nobody knows was never sent.
 */
final class ChannelNotImplemented extends BaseException
{
    protected int $status = 501;

    protected bool $reportable = true;

    public static function for(NotificationType $type): self
    {
        return (new self(sprintf(
            'The "%s" notification channel has no provider configured.',
            $type->value,
        )))->withContext(['channel' => $type->value]);
    }
}
