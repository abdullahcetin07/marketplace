<?php

declare(strict_types=1);

use App\Core\Domain\Exceptions\BaseException;
use App\Core\Presentation\Middleware\CaptureAuditContext;
use App\Http\Middleware\AssignCorrelationId;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
        | Runs first so every log line, event and job dispatched during the
        | request carries the same correlation id.
        */
        $middleware->prepend(AssignCorrelationId::class);

        /*
        | Pushes the request's IP, user agent and URL into the Domain layer as
        | an explicit context (ADR-019), so `Auditable` never calls request().
        | Must run AFTER AssignCorrelationId — it reads the correlation id.
        */
        $middleware->append(CaptureAuditContext::class);

        /*
        | Behind a load balancer the app must trust X-Forwarded-* or every
        | generated URL is http:// and every client IP is the balancer's.
        | TRUSTED_PROXIES should name the balancer's CIDR in production; '*'
        | is acceptable only when the app is unreachable except through it.
        */
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*'),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        /*
        | Sanctum SPA support: requests from a stateful domain are authenticated
        | with the session cookie and CSRF token rather than a bearer token.
        */
        $middleware->statefulApi();

        $middleware->api(prepend: [
            Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
        ]);

        /*
        | CSRF is on for every web route. The API routes are exempt only where
        | they are genuinely token-authenticated; Sanctum's stateful requests
        | still validate the token.
        */
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);

        $middleware->alias([
            'role' => Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'abilities' => Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
        ]);

        /*
        | The three guards, in the order redirects should consider them.
        */
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->expectsJson()
            ? null
            : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
        | Domain exceptions render and report themselves — see BaseException.
        | Everything below handles the framework's own exception types so the
        | API always answers with the same envelope.
        */

        $exceptions->render(function (AuthenticationException $e, Request $request): ?Response {
            if (! $request->expectsJson()) {
                return null;
            }

            // ADR-009 canonical envelope. @see BaseException::render()
            return response()->json([
                'success' => false,
                'code' => 'UNAUTHENTICATED',
                'message' => __('errors.unauthenticated'),
            ], 401);
        });

        /*
        | Anything unexpected is written to the dedicated errors channel with
        | the correlation id attached, so a customer report can be traced to a
        | stack trace in one lookup.
        */
        $exceptions->report(function (Throwable $e): void {
            if ($e instanceof BaseException) {
                return;
            }

            Log::channel('errors')->error($e->getMessage(), [
                'exception' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        });

        /*
        | Never leak an internal message on a 500 in production.
        */
        $exceptions->dontReportDuplicates();
    })
    ->create();
