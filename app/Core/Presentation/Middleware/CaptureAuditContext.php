<?php

declare(strict_types=1);

namespace App\Core\Presentation\Middleware;

use App\Core\Domain\Context\AuditContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Captures the request facts the audit trail needs and pushes them into the
 * Domain layer.
 *
 * This is the Presentation half of ADR-019: HTTP access belongs here, and the
 * Domain layer reads a plain value object rather than reaching for `request()`.
 *
 * Runs after AssignCorrelationId so the correlation id is available.
 *
 * @see App\Core\Domain\Context\AuditContext
 * @see docs/audit.md
 */
final class CaptureAuditContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $agent = $this->parseUserAgent($request->userAgent());

        AuditContext::set(new AuditContext(
            ipAddress: $request->ip(),
            // Truncated: some bots send kilobyte-long agents, and this value
            // lands in a varchar(512).
            userAgent: $this->truncate($request->userAgent(), 512),
            browser: $agent['browser'],
            platform: $agent['platform'],
            url: $this->truncate($request->fullUrl(), 512),
            correlationId: correlation_id() ?: null,
            origin: 'http',
        ));

        try {
            return $next($request);
        } finally {
            // A long-lived worker (Octane) must not leak one request's context
            // into the next.
            AuditContext::forget();
        }
    }

    /**
     * Crude UA classification — for a human reading an audit page, not for
     * analytics. A UA-parsing dependency needs constant updating to stay
     * accurate and buys nothing here.
     *
     * @return array{browser: string|null, platform: string|null}
     */
    private function parseUserAgent(?string $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return ['browser' => null, 'platform' => null];
        }

        return [
            'browser' => match (true) {
                str_contains($raw, 'Edg/') => 'Edge',
                str_contains($raw, 'OPR/') => 'Opera',
                str_contains($raw, 'Firefox/') => 'Firefox',
                // Chrome's UA contains "Safari" — order matters.
                str_contains($raw, 'Chrome/') => 'Chrome',
                str_contains($raw, 'Safari/') => 'Safari',
                default => null,
            },
            'platform' => match (true) {
                str_contains($raw, 'Windows') => 'Windows',
                str_contains($raw, 'Android') => 'Android',
                str_contains($raw, 'iPhone'), str_contains($raw, 'iPad') => 'iOS',
                str_contains($raw, 'Mac OS X') => 'macOS',
                str_contains($raw, 'Linux') => 'Linux',
                default => null,
            },
        ];
    }

    private function truncate(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
