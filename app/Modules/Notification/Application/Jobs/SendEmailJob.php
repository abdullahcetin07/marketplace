<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Jobs;

use App\Core\Application\Jobs\BaseJob;
use App\Models\User;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * Send one email.
 *
 * MOST MAIL SHOULD NOT USE THIS. Laravel notifications already queue
 * themselves, and `BaseNotification` routes them onto the right queue. This
 * job exists for the cases notifications do not cover — an operator sending a
 * one-off Mailable, a scheduled digest built outside a notification.
 *
 * The recipient's LOCALE is captured at dispatch and applied at send time.
 * Without it the mail renders in whatever locale the worker happened to be in,
 * which is usually the platform default — so a Turkish customer receives an
 * English email because an English-speaking admin triggered it.
 *
 * @see App\Core\Application\Notifications\BaseNotification
 * @see docs/notifications.md
 */
final class SendEmailJob extends BaseJob
{
    public function __construct(
        private readonly string $to,
        private readonly Mailable $mailable,
        private readonly ?string $locale = null,
    ) {
        parent::__construct();
    }

    public static function toUser(User $user, Mailable $mailable): self
    {
        return new self($user->email, $mailable, $user->preferredLocale());
    }

    public function handle(): void
    {
        $mailer = Mail::to($this->to);

        if ($this->locale !== null) {
            $mailer->locale($this->locale);
        }

        $mailer->send($this->mailable);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            ...parent::tags(),
            'mailable:'.class_basename($this->mailable),
        ];
    }

    protected function queueName(): string
    {
        // Highest-priority queue: a password reset is a user waiting.
        return 'notifications';
    }
}
