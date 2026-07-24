<?php

declare(strict_types=1);

namespace Database\Modules\Audit\Factories;

use App\Models\Admin;
use App\Models\User;
use App\Modules\Audit\Domain\Enums\AuditEventType;
use App\Modules\Audit\Domain\Enums\AuditSeverity;
use App\Modules\Audit\Domain\Models\AuditEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @extends Factory<AuditEntry>
 */
final class AuditEntryFactory extends Factory
{
    protected $model = AuditEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'event_type' => AuditEventType::ModelUpdated,
            'severity' => AuditSeverity::Info,
            'event' => AuditEntry::EVENT_UPDATED,
            'auditable_type' => User::class,
            'auditable_id' => 1,
            'causer_type' => null,
            'causer_id' => null,
            'old_values' => ['name' => 'Before'],
            'new_values' => ['name' => 'After'],
            'metadata' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'browser' => 'Chrome',
            'platform' => 'Windows',
            'url' => fake()->url(),
            'correlation_id' => (string) Str::uuid(),
        ];
    }

    public function forModel(Model $model): static
    {
        return $this->state(fn (): array => [
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
        ]);
    }

    public function causedBy(Model $causer): static
    {
        return $this->state(fn (): array => [
            'causer_type' => $causer::class,
            'causer_id' => $causer->getKey(),
        ]);
    }

    public function byAdmin(): static
    {
        return $this->state(fn (): array => [
            'causer_type' => Admin::class,
            'causer_id' => Admin::factory(),
        ]);
    }

    /**
     * A system write — no causer. The default for seeders, queue workers and
     * console commands, and the case that must not be attributed to a person.
     */
    public function bySystem(): static
    {
        return $this->state(fn (): array => [
            'causer_type' => null,
            'causer_id' => null,
        ]);
    }

    public function created(): static
    {
        return $this->state(fn (): array => [
            'event_type' => AuditEventType::ModelCreated,
            'event' => AuditEntry::EVENT_CREATED,
            'old_values' => null,
            'new_values' => ['name' => fake()->name()],
        ]);
    }

    public function deleted(): static
    {
        return $this->state(fn (): array => [
            'event_type' => AuditEventType::ModelDeleted,
            'event' => AuditEntry::EVENT_DELETED,
            'old_values' => ['name' => fake()->name()],
            'new_values' => null,
        ]);
    }

    /**
     * A standalone security event — no model diff. The shape a detected attack
     * takes: a type, a high severity, context in metadata, and often no
     * auditable record at all.
     */
    public function security(
        AuditEventType $type = AuditEventType::SecurityBruteForce,
        AuditSeverity $severity = AuditSeverity::High,
    ): static {
        return $this->state(fn (): array => [
            'event_type' => $type,
            'severity' => $severity,
            'event' => null,
            'auditable_type' => null,
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => null,
            'metadata' => ['email' => fake()->safeEmail(), 'failure_count' => 12],
        ]);
    }

    public function withCorrelation(string $correlationId): static
    {
        return $this->state(fn (): array => ['correlation_id' => $correlationId]);
    }
}
