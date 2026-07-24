<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Jobs;

use App\Core\Application\Jobs\BaseJob;
use App\Modules\Notification\Domain\Contracts\SmsProvider;
use App\Modules\Notification\Domain\Exceptions\ChannelNotImplemented;
use App\Shared\Enums\NotificationType;
use Illuminate\Support\Facades\Log;

/**
 * Send one SMS.
 *
 * NO PROVIDER IS BOUND IN SPRINT 1. The job resolves `SmsProvider` from the
 * container and fails if nothing is bound — deliberately, so an SMS that
 * cannot be delivered lands in `failed_jobs` and is visible in Horizon rather
 * than vanishing.
 *
 * Turning SMS on later is one binding in a service provider. Nothing else in
 * the platform changes.
 *
 * `$tries = 2`, not the inherited 3: SMS costs money per attempt, and a
 * gateway that rejected a message twice will reject it a third time.
 *
 * @see App\Modules\Notification\Infrastructure\Channels\SmsChannel
 * @see docs/notifications.md
 */
final class SendSmsJob extends BaseJob
{
    public int $tries = 2;

    public function __construct(
        private readonly string $to,
        private readonly string $message,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        if (settings()->boolean('sms.enabled') === false) {
            Log::channel('daily')->debug('SMS job skipped: channel disabled', ['to' => $this->maskedRecipient()]);

            return;
        }

        if (! app()->bound(SmsProvider::class)) {
            throw ChannelNotImplemented::for(NotificationType::Sms);
        }

        app(SmsProvider::class)->send($this->to, $this->message);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [...parent::tags(), 'sms'];
    }

    /**
     * Horizon displays tags and failure context to anyone with dashboard
     * access; a full phone number there is personal data on a screen with a
     * wider audience than it needs.
     */
    private function maskedRecipient(): string
    {
        return mb_substr($this->to, 0, 4).str_repeat('*', max(0, mb_strlen($this->to) - 6)).mb_substr($this->to, -2);
    }

    protected function queueName(): string
    {
        return 'notifications';
    }
}
