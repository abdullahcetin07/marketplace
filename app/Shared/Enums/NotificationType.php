<?php

declare(strict_types=1);

namespace App\Shared\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * Delivery channels a notification can be dispatched over.
 *
 * An enum rather than a lookup table: channels are code concepts. Adding one
 * means writing a driver, so it can never be "enabled" by an administrator
 * without a deploy. Contrast with Country/Currency/Language, which are data.
 *
 * @see docs/001_Architecture.md §"Enums vs lookup tables"
 * @see docs/notifications.md
 */
enum NotificationType: string
{
    use HasEnumHelpers;

    case Database = 'database';
    case Mail = 'mail';
    case Sms = 'sms';
    case Push = 'push';
    case Broadcast = 'broadcast';

    /**
     * Channels with a working driver today. Sms and Push have queued jobs and
     * a channel contract but no provider — Sprint 1 ships the infrastructure,
     * not the integrations.
     *
     * @return array<int, self>
     */
    public static function implemented(): array
    {
        return [self::Database, self::Mail];
    }

    /**
     * Laravel notification channel name returned from `via()`.
     */
    public function channel(): string
    {
        return match ($this) {
            self::Database => 'database',
            self::Mail => 'mail',
            self::Sms => \App\Modules\Notification\Infrastructure\Channels\SmsChannel::class,
            self::Push => \App\Modules\Notification\Infrastructure\Channels\PushChannel::class,
            self::Broadcast => 'broadcast',
        };
    }

    /**
     * Queue each channel is dispatched onto. Mail and SMS are latency-sensitive
     * (a password reset is a user waiting); push and broadcast are not.
     */
    public function queue(): string
    {
        return match ($this) {
            self::Database => 'default',
            self::Mail, self::Sms => 'notifications',
            self::Push, self::Broadcast => 'default',
        };
    }

    public function isImplemented(): bool
    {
        return in_array($this, self::implemented(), true);
    }

    /**
     * Whether a user may switch this channel off. Database notifications are
     * the in-app inbox and cannot be disabled, or the user loses their record
     * of what happened.
     */
    public function isOptOutable(): bool
    {
        return $this !== self::Database;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Database => 'heroicon-o-bell',
            self::Mail => 'heroicon-o-envelope',
            self::Sms => 'heroicon-o-device-phone-mobile',
            self::Push => 'heroicon-o-signal',
            self::Broadcast => 'heroicon-o-megaphone',
        };
    }
}
