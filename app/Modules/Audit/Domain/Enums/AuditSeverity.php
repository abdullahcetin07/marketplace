<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * How much a forensic event should worry a human reading the trail.
 *
 * INDEPENDENT OF `AuditEventType` on purpose (the ruling that made Audit a
 * forensic store, ADR-027). The same event type carries different weight in
 * different contexts: a login is `Info`, a login *storm* is `High`. Coupling
 * severity to type would force that judgement at enum-definition time, where the
 * context that decides it does not exist.
 *
 * The scale maps onto syslog/PSR levels so a future SIEM export needs no
 * translation table — @see logLevel().
 *
 * @see App\Modules\Audit\Domain\Enums\AuditEventType
 * @see docs/audit.md
 */
enum AuditSeverity: string
{
    use HasEnumHelpers;

    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case High = 'high';
    case Critical = 'critical';

    /**
     * Ordinal weight, so "at least Warning" is a comparison rather than a set
     * membership test. Gaps left between values deliberately — a level can be
     * inserted later without renumbering.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Info => 10,
            self::Notice => 20,
            self::Warning => 30,
            self::High => 40,
            self::Critical => 50,
        };
    }

    /**
     * True when this severity is at or above another — the predicate behind
     * "alert on Warning and worse".
     */
    public function atLeast(self $floor): bool
    {
        return $this->weight() >= $floor->weight();
    }

    /**
     * The PSR-3 / syslog level this maps to. Keeps a log-channel line and an
     * audit row describing the same event at the same level, and gives a SIEM
     * export a standard severity without a bespoke mapping.
     */
    public function logLevel(): string
    {
        return match ($this) {
            self::Info => 'info',
            self::Notice => 'notice',
            self::Warning => 'warning',
            self::High => 'error',
            self::Critical => 'critical',
        };
    }
}
