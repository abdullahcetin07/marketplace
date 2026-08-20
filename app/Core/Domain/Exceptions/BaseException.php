<?php

declare(strict_types=1);

namespace App\Core\Domain\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Root of every deliberate, domain-level failure.
 *
 * WHY: a marketplace fails in ways that are expected — a store is not approved,
 * an offer is out of stock, a seller edits another seller's listing. Those are
 * not bugs and must not surface as 500s or reach the error tracker. Extending
 * this class makes a failure self-describing: it carries its own HTTP status,
 * a machine-readable error code the Next.js frontend can branch on, and a
 * translated message safe to show a user.
 *
 * Genuine bugs should keep throwing native exceptions so they stay loud.
 *
 *     final class StoreNotApproved extends BaseException
 *     {
 *         protected int $status = 403;
 *
 *         public static function for(Store $store): self
 *         {
 *             return (new self)->withContext(['store_id' => $store->id]);
 *         }
 *     }
 *
 * @see docs/error-handling.md
 */
abstract class BaseException extends Exception implements HttpExceptionInterface
{
    /**
     * HTTP status used when this exception escapes to the HTTP layer.
     */
    protected int $status = Response::HTTP_UNPROCESSABLE_ENTITY;

    /**
     * Stable machine-readable code. Derived from the class name when null.
     */
    protected ?string $errorCode = null;

    /**
     * Whether the error tracker should be notified. Expected domain failures
     * default to false so genuine incidents are not buried in noise.
     */
    protected bool $reportable = false;

    /**
     * @var array<string, mixed>
     */
    protected array $context = [];

    public static function make(string $message = '', ?Throwable $previous = null): static
    {
        return new static($message, 0, $previous); // @phpstan-ignore-line
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * The same status, under the name the framework looks for.
     *
     * WHY THIS INTERFACE EXISTS ON A DOMAIN CLASS: `render()` below deliberately
     * returns null for a non-JSON request so Laravel can draw its own error page.
     * But Laravel only knows the status of an exception that implements
     * `HttpExceptionInterface`; anything else is a 500 by definition. So for
     * years every domain failure — a missing store, an unapproved seller —
     * answered a BROWSER with `500 Server Error` while answering the API with a
     * correct 404 or 403. It surfaced the day 110 test stores were deleted and
     * `/api/v1/magaza/{slug}` started 500ing in a browser (2026-08-20).
     *
     * A 500 is not a cosmetic difference from a 404: it tells a crawler the site
     * is broken rather than the page is gone, and it tells an operator to go
     * looking for an incident that never happened.
     */
    public function getStatusCode(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }

    /**
     * The wire code: `STORE_NOT_APPROVED`.
     *
     * UPPER_SNAKE_CASE per 005_API_Standards §25. Stable and machine-readable —
     * clients branch on this, never on `message`, which is translated and will
     * change.
     */
    public function getErrorCode(): string
    {
        return Str::upper($this->translationKey());
    }

    /**
     * The lookup key: `store_not_approved`.
     *
     * Kept lowercase and separate from the wire code so translation files stay
     * readable — `errors.store_not_approved`, not `errors.STORE_NOT_APPROVED`.
     */
    public function translationKey(): string
    {
        return $this->errorCode ??= Str::snake(class_basename(static::class));
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function withContext(array $context): static
    {
        $this->context = [...$this->context, ...$context];

        return $this;
    }

    public function withStatus(int $status): static
    {
        $this->status = $status;

        return $this;
    }

    /**
     * User-facing message. Prefers a translation keyed by the error code so the
     * same exception renders in Turkish for the panel and English for the API.
     */
    public function userMessage(): string
    {
        $key = 'errors.'.$this->translationKey();
        $translated = __($key, $this->context);

        if (is_string($translated) && $translated !== $key) {
            return $translated;
        }

        return $this->getMessage() !== '' ? $this->getMessage() : __('errors.generic');
    }

    /**
     * Laravel calls this automatically to decide whether to report.
     */
    public function report(): bool
    {
        // Returning false tells the handler to stop — i.e. do not report.
        return $this->reportable;
    }

    /**
     * Laravel calls this automatically to render the exception.
     */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            // Let Laravel draw the HTML error page. It picks the right one
            // because this class implements `HttpExceptionInterface` — see
            // `getStatusCode()`; without it every one of these was a 500.
            return null;
        }

        /*
        | ADR-009 canonical error envelope. One shape for every failure —
        | validation, authorization, domain, framework — so a client needs
        | exactly one branch.
        |
        | `errors` is included only when applicable: a 422 carries field
        | errors, a 404 does not. @see 005_API_Standards §7
        */
        $payload = [
            'success' => false,
            'code' => $this->getErrorCode(),
            'message' => $this->userMessage(),
        ];

        if ($this->context !== []) {
            $payload['errors'] = $this->context;
        }

        return response()->json($payload, $this->status);
    }

    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'exception' => static::class,
            'code' => $this->getErrorCode(),
            'status' => $this->status,
            'context' => $this->context,
        ];
    }
}
