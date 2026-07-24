<?php

declare(strict_types=1);

namespace App\Core\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Root of every domain event in the platform.
 *
 * WHY A BASE CLASS: cross-module communication happens through events, not
 * direct service calls — that is what keeps app/Modules/* decoupled enough to
 * be developed and tested in isolation. For that to work, every event needs a
 * stable name, a correlation id and a timestamp, so a payload can be traced
 * from the HTTP request that caused it through every listener that reacted.
 *
 * Events are past-tense facts and are immutable. Name them accordingly:
 * StoreApproved, OfferPriceChanged — never ApproveStore.
 *
 *     final class StoreApproved extends BaseEvent
 *     {
 *         public function __construct(public readonly int $storeId) {}
 *     }
 *
 * @see docs/001_Architecture.md §"Events between modules"
 */
abstract class BaseEvent
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Correlates every event raised during one request or job run.
     */
    public readonly string $correlationId;

    public readonly string $occurredAt;

    public function __construct()
    {
        $this->correlationId = self::currentCorrelationId();
        $this->occurredAt = now()->toIso8601String();
    }

    /**
     * Stable, transport-agnostic event name: `store.approved`.
     *
     * Derived from the class name so it cannot drift, but overridable when an
     * event is renamed and external consumers must keep seeing the old name.
     */
    public function name(): string
    {
        return Str::of(class_basename(static::class))->snake('.')->value();
    }

    /**
     * Payload as broadcast/logged. Defaults to every public property except
     * the envelope fields, which are added separately by toLogContext().
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $properties = get_object_vars($this);

        unset($properties['correlationId'], $properties['occurredAt']);

        return $properties;
    }

    /**
     * Structured context written to the audit channel by the global event
     * subscriber. @see App\Providers\EventServiceProvider
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'event' => $this->name(),
            'event_class' => static::class,
            'correlation_id' => $this->correlationId,
            'occurred_at' => $this->occurredAt,
            'payload' => $this->payload(),
        ];
    }

    /**
     * Whether this event should be written to the audit log. Override and
     * return false for high-frequency, low-value events.
     */
    public function shouldAudit(): bool
    {
        return true;
    }

    /**
     * Reuse the request id set by the correlation middleware so events, logs
     * and queued jobs all share one id. Falls back to a fresh UUID in console
     * and test contexts.
     */
    private static function currentCorrelationId(): string
    {
        $id = app()->bound('correlation_id') ? app('correlation_id') : null;

        return is_string($id) && $id !== '' ? $id : (string) Str::uuid();
    }
}
