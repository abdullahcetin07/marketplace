<?php

declare(strict_types=1);

namespace App\Core\Domain\Context;

/**
 * The ambient request facts an audit entry needs, pushed in from Presentation.
 *
 * WHY THIS EXISTS: ADR-019 forbids `request()` in the Domain layer. The
 * `Auditable` trait previously pulled the IP, user agent and URL straight from
 * the container, which had two problems:
 *
 *  1. It made a Domain trait untestable without booting an HTTP kernel.
 *  2. **It was quietly wrong outside HTTP.** In a queue worker, a console
 *     command or a seeder, `request()` yields a synthetic request and every
 *     captured field came out null — with no indication that the values were
 *     meaningless rather than absent.
 *
 * This class fixes both. Presentation populates it per request; everything else
 * gets `system()`, which is explicit about being a non-HTTP origin.
 *
 * A static holder rather than an injected service, because Eloquent model
 * events give no seam to inject through. The holder itself touches no
 * container, no facade and no I/O — it is a plain value object with a static
 * slot, which is what keeps the Domain layer pure.
 *
 * @see App\Core\Presentation\Middleware\CaptureAuditContext
 * @see App\Modules\Audit\Domain\Concerns\Auditable
 * @see docs/audit.md
 */
final class AuditContext
{
    private static ?self $current = null;

    public function __construct(
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $browser = null,
        public readonly ?string $platform = null,
        public readonly ?string $url = null,
        public readonly ?string $correlationId = null,
        /** 'http' | 'console' | 'queue' | 'test' */
        public readonly string $origin = 'system',
        /**
         * WHY a change was made, when the actor gave one — an admin suspending
         * an account, resetting a password. Self-service edits have none; it is
         * null then, and the audit entry simply omits it. Set per operation via
         * withReason(), not by the middleware, because the reason is specific to
         * the action, not the request.
         */
        public readonly ?string $reason = null,
    ) {}

    /**
     * A copy of this context carrying a reason for the operation about to run.
     *
     * Immutable: returns a new instance rather than mutating, so a reason
     * attached for one write cannot bleed onto an unrelated one.
     */
    public function withReason(?string $reason): self
    {
        return new self(
            ipAddress: $this->ipAddress,
            userAgent: $this->userAgent,
            browser: $this->browser,
            platform: $this->platform,
            url: $this->url,
            correlationId: $this->correlationId,
            origin: $this->origin,
            reason: $reason,
        );
    }

    /**
     * Attach a reason to the current context for the duration of a closure,
     * restoring the previous context afterwards. This is how an admin action
     * records WHY without threading the reason through the model layer.
     *
     * @template TValue
     *
     * @param  \Closure(): TValue  $callback
     * @return TValue
     */
    public static function withReasonFor(?string $reason, \Closure $callback): mixed
    {
        return self::using(self::current()->withReason($reason), $callback);
    }

    /**
     * The context in effect, never null.
     *
     * Falls back to a system context so a caller can never receive null and
     * forget to handle it.
     */
    public static function current(): self
    {
        return self::$current ??= self::system();
    }

    public static function set(self $context): void
    {
        self::$current = $context;
    }

    /**
     * Non-HTTP origin: a console command, a queue worker, a seeder.
     *
     * All request fields are null, and `origin` says why — which is the
     * distinction the old implementation could not express.
     */
    public static function system(string $origin = 'system'): self
    {
        return new self(origin: $origin);
    }

    /**
     * Clear the holder. Called between queue jobs and in test teardown, so one
     * request's context cannot leak into the next on a long-lived worker.
     */
    public static function forget(): void
    {
        self::$current = null;
    }

    /**
     * Run a callback under a specific context, restoring the previous one
     * afterwards. Used by queue listeners to rehydrate the dispatching
     * request's context around a job.
     *
     * @template TValue
     *
     * @param  \Closure(): TValue  $callback
     * @return TValue
     */
    public static function using(self $context, \Closure $callback): mixed
    {
        $previous = self::$current;
        self::$current = $context;

        try {
            return $callback();
        } finally {
            self::$current = $previous;
        }
    }

    public function isHttp(): bool
    {
        return $this->origin === 'http';
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'browser' => $this->browser,
            'platform' => $this->platform,
            'url' => $this->url,
            'correlation_id' => $this->correlationId,
        ];
    }
}
