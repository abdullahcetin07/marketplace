<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\Models;

use App\Models\User;
use App\Shared\Enums\NotificationType;
use App\Shared\Traits\HasUuid;
use Database\Modules\Notification\Factories\NotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's opt-out from one notification channel.
 *
 * DENY-LIST, NOT ALLOW-LIST. A missing row means "send it". An allow-list
 * would mean every new notification type reaches nobody until users opt in,
 * which they never do — the feature ships and silently does nothing.
 *
 * `notification_type` null means the whole channel; a class name narrows the
 * opt-out to one notification, so a user can mute marketing email without
 * muting order updates.
 *
 * TWO THINGS THIS CANNOT SILENCE, enforced in BaseNotification::shouldSendType():
 *   - database notifications, which are the in-app inbox
 *   - security alerts, because a user must not be able to mute the message
 *     telling them their password was changed
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property NotificationType $channel
 * @property string|null $notification_type
 * @property bool $enabled
 *
 * @see docs/notifications.md
 */
final class NotificationPreference extends Model
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use HasFactory;

    use HasUuid;

    protected $table = 'notification_preferences';

    protected $fillable = [
        'user_id',
        'channel',
        'notification_type',
        'enabled',
    ];

    /**
     * Whether a user has opted out of a channel for a given notification.
     *
     * Checks the narrow rule first, then the channel-wide one — a per-type
     * preference must be able to override a blanket opt-out, or a user who
     * muted a whole channel can never re-enable one message within it.
     */
    public static function hasOptedOut(
        User $user,
        NotificationType $channel,
        ?string $notificationClass = null,
    ): bool {
        $preferences = self::query()
            ->where('user_id', $user->getKey())
            ->where('channel', $channel->value)
            ->get();

        if ($notificationClass !== null) {
            $specific = $preferences->firstWhere('notification_type', $notificationClass);

            if ($specific !== null) {
                return ! $specific->enabled;
            }
        }

        $blanket = $preferences->firstWhere('notification_type', null);

        return $blanket !== null && ! $blanket->enabled;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => NotificationType::class,
            'enabled' => 'boolean',
        ];
    }
}
