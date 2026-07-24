<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an id that follows it into logs, events and queued jobs.
 *
 * WHY: in a system with three panels, an API, and a fleet of Horizon workers,
 * "the customer says checkout failed at 14:32" is not enough to find anything.
 * A correlation id turns that into one grep. It is accepted from the incoming
 * X-Request-Id header when the Next.js frontend supplies one, so a trace spans
 * frontend and backend.
 *
 * Registered globally in bootstrap/app.php.
 *
 * @see App\Core\Domain\Events\BaseEvent
 * @see App\Core\Application\Jobs\BaseJob
 * @see docs/logging.md
 */
final class AssignCorrelationId
{
    public const string HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $this->resolve($request);

        app()->instance('correlation_id', $correlationId);

        // Attach to every log line written during this request.
        Log::shareContext(['correlation_id' => $correlationId]);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }

    /**
     * Reuse the inbound id when it is present and plausible; a client must not
     * be able to inject arbitrary length or content into every log line.
     */
    private function resolve(Request $request): string
    {
        $incoming = $request->header(self::HEADER);

        if (is_string($incoming) && preg_match('/^[A-Za-z0-9\-]{8,64}$/', $incoming) === 1) {
            return $incoming;
        }

        return (string) Str::uuid();
    }
}
