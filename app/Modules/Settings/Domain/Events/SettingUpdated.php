<?php

declare(strict_types=1);

namespace App\Modules\Settings\Domain\Events;

use App\Core\Domain\Events\BaseEvent;
use App\Modules\Settings\Domain\Enums\SettingGroup;

/**
 * A platform setting changed.
 *
 * Encrypted settings dispatch `'[redacted]'` for both values — see
 * SettingsService::set(). An SMTP password must not travel through the audit
 * log and the queue in plaintext just because it changed.
 *
 * Listeners that cache derived state (mail transport configuration, feature
 * flags) subscribe to this rather than polling.
 */
final class SettingUpdated extends BaseEvent
{
    public function __construct(
        public readonly string $key,
        public readonly SettingGroup $group,
        public readonly mixed $oldValue,
        public readonly mixed $newValue,
        public readonly ?int $causerId = null,
    ) {
        parent::__construct();
    }

    /**
     * Whether the change touches a group that requires a service to be
     * reconfigured — mail transport, SMS provider — as opposed to cosmetic
     * copy that takes effect on the next read.
     */
    public function requiresReconfiguration(): bool
    {
        return in_array($this->group, [
            SettingGroup::Email,
            SettingGroup::Sms,
            SettingGroup::Media,
            SettingGroup::Performance,
        ], true);
    }
}
