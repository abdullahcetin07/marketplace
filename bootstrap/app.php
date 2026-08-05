<?php

declare(strict_types=1);

use App\Core\Presentation\Middleware\CaptureAuditContext;
use App\Http\Middleware\AssignCorrelationId;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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

        /*
        | NOTE: CheckAbilities is deliberately NOT prepended onto the api group.
        | As a global middleware it demands `$request->user()->currentAccessToken()`
        | on EVERY /api/v1 request, which 401s both public endpoints (the public
        | storefront) and session/cookie-authenticated ones (no access token) —
        | only bearer-token requests survive. Token-ability checks belong on the
        | specific routes that need them, via the `abilities` / `ability` aliases
        | registered below.
        */

        /*
        | CSRF is on for every web route. The API routes are exempt only where
        | they are genuinely token-authenticated; Sanctum's stateful requests
        | still validate the token.
        |
        | A PSP CALLBACK IS THE ONE THING THAT CANNOT CARRY A CSRF TOKEN, and it
        | is exempted for a reason that has nothing to do with convenience: PayTR
        | posts server-to-server, from its own network, with no browser and no
        | session, and it retries until it is answered `"OK"`. A 419 there means
        | money collected and an order never confirmed — the one state on this
        | platform that nothing later can repair.
        |
        | IT IS NOT UNPROTECTED. What CSRF defends is a browser being tricked into
        | using a session it already holds; this endpoint has no session to abuse
        | and authenticates the SENDER instead, by recomputing PayTR's HMAC over
        | the posted fields with the merchant key (Payment.md §3). A forged POST
        | without that key changes nothing, which is a stronger guarantee than a
        | token a cookie-bearing browser would have supplied anyway.
        |
        | THE STATEFUL SHORTCUT IS NOT A DEFENCE. Sanctum only promotes a request
        | to the session stack when its Origin/Referer names a stateful domain, so
        | PayTR's callback happens not to be checked today — but that is a header
        | a third party controls, and "our payment settlement works because the
        | PSP does not send a Referer" is not a property to rely on.
        */
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
            'api/v1/payments/paytr/callback',
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
        | Unexpected exceptions reach the dedicated `errors` channel through the
        | FRAMEWORK'S OWN reporting, not a callback here: the default log channel
        | is the `stack`, and that stack is `daily,errors` (config/logging.php).
        | Laravel's handler already writes the class, file, line AND the stack
        | trace — strictly more than a hand-rolled reporter did.
        |
        | There is deliberately no report() callback using the Log facade. The
        | exception handler is installed by HandleExceptions, which runs BEFORE
        | RegisterFacades — so a facade call in a reporter has no root during
        | early bootstrap, and every such failure gets replaced by
        | "A facade root has not been set", destroying the real exception.
        */

        /*
        | One report per exception instance, however many handlers observe it.
        */
        $exceptions->dontReportDuplicates();
    })
    ->create();
