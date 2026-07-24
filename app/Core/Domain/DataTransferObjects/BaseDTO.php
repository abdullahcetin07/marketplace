<?php

declare(strict_types=1);

namespace App\Core\Domain\DataTransferObjects;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use JsonSerializable;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Immutable data carrier that crosses layer boundaries.
 *
 * WHY DTOs AT ALL: controllers must not hand raw arrays or Request objects to
 * services. An array has no contract — every consumer re-guesses its shape and
 * a typo becomes a runtime null. A DTO makes the shape explicit and lets
 * PHPStan check it at level 6.
 *
 * Subclasses declare readonly promoted constructor properties and nothing else:
 *
 *     final class CreateStoreData extends BaseDTO
 *     {
 *         public function __construct(
 *             public readonly string $name,
 *             public readonly Country $country,
 *             public readonly ?string $taxNumber = null,
 *         ) {}
 *     }
 *
 * `fromArray()` hydrates by reflecting the constructor, accepting both camelCase
 * and snake_case keys so an HTTP payload maps without a manual translation
 * layer. Enum-typed and nested-DTO-typed parameters are cast automatically.
 *
 * DTOs are NOT validated. Validation belongs to the FormRequest that produces
 * them — see App\Core\Presentation\Requests\BaseRequest.
 */
abstract class BaseDTO implements Arrayable, Jsonable, JsonSerializable
{
    /**
     * Hydrate from a loosely-keyed array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $constructor = (new ReflectionClass(static::class))->getConstructor();

        if ($constructor === null) {
            return new static; // @phpstan-ignore-line — no-arg DTO
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $value = self::extractValue($data, $parameter);

            if ($value === null && ! array_key_exists($parameter->getName(), $data)) {
                // Absent key: fall back to the declared default rather than
                // forcing every optional field to be spelled out by callers.
                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();

                    continue;
                }
            }

            $arguments[] = self::castValue($value, $parameter);
        }

        return new static(...$arguments);
    }

    /**
     * Hydrate from validated request input.
     *
     * Uses validated() when available so unvalidated keys can never reach the
     * domain, even if a caller passes a plain Request by mistake.
     */
    public static function fromRequest(Request $request): static
    {
        /** @var array<string, mixed> $data */
        $data = method_exists($request, 'validated')
            ? $request->validated()
            : $request->all();

        return static::fromArray($data);
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $rows
     * @return Collection<int, static>
     */
    public static function collect(iterable $rows): Collection
    {
        return collect($rows)->map(static fn (array $row): static => static::fromArray($row));
    }

    /**
     * Recursively convert to a snake_case array suitable for persistence.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];

        foreach (get_object_vars($this) as $key => $value) {
            $result[str($key)->snake()->value()] = self::normalise($value);
        }

        return $result;
    }

    /**
     * Array with nulls stripped — for PATCH-style partial updates where a null
     * means "not supplied" rather than "set to null".
     *
     * @return array<string, mixed>
     */
    public function toFilledArray(): array
    {
        return array_filter(
            $this->toArray(),
            static fn (mixed $value): bool => $value !== null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Copy with selected properties replaced. DTOs are readonly, so mutation
     * is expressed as derivation.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function with(array $overrides): static
    {
        return static::fromArray([...$this->toRawArray(), ...$overrides]);
    }

    public function has(string $property): bool
    {
        return property_exists($this, $property) && $this->{$property} !== null;
    }

    /**
     * Property values keyed by their original camelCase names, without the
     * snake_case conversion toArray() applies.
     *
     * @return array<string, mixed>
     */
    protected function toRawArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * Look a parameter up under both its camelCase and snake_case spellings.
     *
     * @param  array<string, mixed>  $data
     */
    private static function extractValue(array $data, ReflectionParameter $parameter): mixed
    {
        $name = $parameter->getName();

        return $data[$name]
            ?? $data[str($name)->snake()->value()]
            ?? null;
    }

    /**
     * Promote scalars to the parameter's declared enum or nested-DTO type.
     */
    private static function castValue(mixed $value, ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        if ($value === null || ! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return $value;
        }

        /** @var class-string $class */
        $class = $type->getName();

        if ($value instanceof $class) {
            return $value;
        }

        if (is_subclass_of($class, BackedEnum::class) && (is_string($value) || is_int($value))) {
            return $class::tryFrom($value);
        }

        if (is_subclass_of($class, self::class) && is_array($value)) {
            return $class::fromArray($value);
        }

        return $value;
    }

    private static function normalise(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof Arrayable => $value->toArray(),
            is_array($value) => array_map(self::normalise(...), $value),
            default => $value,
        };
    }
}
