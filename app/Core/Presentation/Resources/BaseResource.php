<?php

declare(strict_types=1);

namespace App\Core\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Root of every API resource.
 *
 * ONE RULE THIS ENFORCES: internal primary keys never leave the application.
 * The `id` a client sees is the model's UUID. Exposing sequential ids on a
 * marketplace API leaks business volume — a competitor can register, place one
 * order, and read off the platform's order count.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @mixin TModel
 */
abstract class BaseResource extends JsonResource
{
    /**
     * Resources are already wrapped by BaseController's `data` envelope;
     * Laravel's own wrapping would produce `data.data`.
     */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(Request $request): array;

    /**
     * The public identifier. Always the UUID, never the primary key.
     */
    protected function publicId(): string
    {
        return (string) $this->resource->uuid;
    }

    /**
     * Timestamps in a single format (ISO-8601, UTC offset preserved) so the
     * frontend never has to guess.
     *
     * @return array<string, string|null>
     */
    protected function timestamps(): array
    {
        return [
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Include a value only when the actor holds a permission.
     *
     * Use for fields that are legitimate for staff but not for the public —
     * a seller's margin, an internal moderation note.
     */
    protected function whenPermitted(string $permission, mixed $value): mixed
    {
        $actor = current_actor();

        return $this->when(
            $actor !== null && $actor->hasPermissionTo($permission, $actor->guardName()),
            $value,
        );
    }
}
