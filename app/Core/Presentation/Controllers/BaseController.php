<?php

declare(strict_types=1);

namespace App\Core\Presentation\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Root of every API controller.
 *
 * Controllers in this codebase are thin by contract: resolve a DTO from the
 * request, authorise, call one service or action, return a resource. They
 * contain no queries and no business rules.
 *
 * The response helpers below exist so the JSON envelope the Next.js frontend
 * consumes is identical everywhere — `{ data, meta }` on success and
 * `{ error: { code, message, context } }` on failure (produced by
 * BaseException). A frontend should never have to special-case an endpoint.
 */
abstract class BaseController extends Controller
{
    use AuthorizesRequests;
    use ValidatesRequests;

    /**
     * @param array<string, mixed> $meta
     */
    protected function ok(mixed $data = null, ?string $message = null, array $meta = []): JsonResponse
    {
        return $this->respond($data, Response::HTTP_OK, $message, $meta);
    }

    /**
     * @param array<string, mixed> $meta
     */
    protected function created(mixed $data = null, ?string $message = null, array $meta = []): JsonResponse
    {
        return $this->respond($data, Response::HTTP_CREATED, $message, $meta);
    }

    /**
     * 204 carries no body at all — an envelope with a 204 is a contradiction,
     * and some clients refuse to parse one.
     */
    protected function noContent(): JsonResponse
    {
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    protected function accepted(mixed $data = null, ?string $message = null): JsonResponse
    {
        return $this->respond($data, Response::HTTP_ACCEPTED, $message);
    }

    /**
     * Paginated payload with the page metadata the frontend needs, without
     * leaking Laravel's URL-bearing pagination format.
     *
     * @param LengthAwarePaginator<int, mixed> $paginator
     */
    protected function paginated(
        LengthAwarePaginator $paginator,
        mixed $data = null,
        ?string $message = null,
    ): JsonResponse {
        // Key names per 005_API_Standards §8 — snake_case (ADR-008).
        return $this->respond($data ?? $paginator->items(), Response::HTTP_OK, $message, [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    /**
     * Per-page size, clamped so a client cannot request the whole table.
     */
    protected function perPage(int $default = 25, int $max = 100): int
    {
        return min(max((int) request()->integer('per_page', $default), 1), $max);
    }

    /**
     * ADR-009 success envelope.
     *
     * `success` is always present so a client can branch on one field for
     * every response, success or failure, without inspecting the status code.
     *
     * `message` and `meta` are omitted when empty rather than sent as null —
     * a null `message` is noise on the ~90% of responses that have nothing to
     * say beyond the data.
     *
     * @param array<string, mixed> $meta
     */
    private function respond(mixed $data, int $status, ?string $message = null, array $meta = []): JsonResponse
    {
        $payload = ['success' => true];

        if ($message !== null && $message !== '') {
            $payload['message'] = $message;
        }

        $payload['data'] = $data;

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }
}
