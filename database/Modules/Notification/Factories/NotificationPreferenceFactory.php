<?php

declare(strict_types=1);

namespace Database\Modules\Notification\Factories;

use App\Models\Customer;
use App\Modules\Notification\Domain\Models\NotificationPreference;
use App\Shared\Enums\NotificationType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NotificationPreference>
 */
final class NotificationPreferenceFactory extends Factory
{
    protected $model = NotificationPreference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => Customer::factory(),
            'channel' => NotificationType::Mail,
            // Null = the whole channel. A class name narrows it to one
            // notification.
            'notification_type' => null,
            'enabled' => true,
        ];
    }

    /**
     * The interesting case: a row that actually suppresses delivery.
     */
    public function optedOut(): static
    {
        return $this->state(fn (): array => ['enabled' => false]);
    }

    public function forChannel(NotificationType $channel): static
    {
        return $this->state(fn (): array => ['channel' => $channel]);
    }

    public function forNotification(string $notificationClass): static
    {
        return $this->state(fn (): array => ['notification_type' => $notificationClass]);
    }
}
