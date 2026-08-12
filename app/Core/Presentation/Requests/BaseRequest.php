<?php

declare(strict_types=1);

namespace App\Core\Presentation\Requests;

use App\Core\Domain\DataTransferObjects\BaseDTO;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use LogicException;

/**
 * Root of every form request.
 *
 * This is the trust boundary. Validation happens here and nowhere else —
 * a DTO is a shape, not a guarantee, and services assume they are given
 * already-valid data.
 *
 * Two conventions worth knowing:
 *
 *  1. authorize() defaults to FALSE. Laravel's default is true, which means a
 *     forgotten override silently opens an endpoint. Inverting that default
 *     turns the same mistake into a visible 403 during development.
 *
 *  2. Validation failures render the same `{ error: { code, message, ... } }`
 *     envelope as BaseException, so the frontend has exactly one error shape
 *     to handle.
 */
abstract class BaseRequest extends FormRequest
{
    /**
     * The DTO this request hydrates, when it has one.
     *
     * @var class-string<BaseDTO>|null
     */
    protected ?string $dto = null;

    /**
     * @return array<string, mixed>
     */
    abstract public function rules(): array;

    /**
     * Deny by default. @see class docblock.
     */
    public function authorize(): bool
    {
        return false;
    }

    /**
     * The authenticated actor, correctly typed, across any of the guards.
     */
    public function actor(): ?User
    {
        /*
        | THE TOKEN GUARDS ARE HERE FOR THE REASON `current_actor()` STATES: a
        | bearer token populates no named guard, so without them a correctly
        | signed API request reads as nobody and every `authorize()` returns
        | false. Session guards stay first — a panel and an API call can share a
        | browser.
        */
        foreach (['admin', 'seller', 'customer', 'sanctum', 'sanctum_seller'] as $guard) {
            $user = $this->user($guard);

            if ($user instanceof User) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Hydrate the declared DTO from validated input.
     */
    public function toDto(): BaseDTO
    {
        if ($this->dto === null) {
            throw new LogicException(static::class.' does not declare a $dto.');
        }

        return $this->dto::fromArray($this->validated());
    }

    /**
     * Return the JSON error envelope instead of Laravel's default shape.
     */
    protected function failedValidation(Validator $validator): void
    {
        if (! $this->expectsJson()) {
            parent::failedValidation($validator);

            return;
        }

        /*
        | ADR-009 canonical envelope. Validation is the one case where `errors`
        | is always present, keyed by field — which is exactly the shape
        | 005_API_Standards §7 specifies.
        */
        throw new HttpResponseException(response()->json([
            'success' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => __('errors.validation_failed'),
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }

    /**
     * Trim strings and turn empty strings into nulls before validation.
     *
     * Without this, a cleared optional field arrives as "" and a `nullable`
     * rule passes it straight through to a NOT NULL column or, worse, stores
     * an empty string where the domain means "absent".
     */
    protected function prepareForValidation(): void
    {
        $this->merge(
            $this->mapDeep($this->all()),
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function mapDeep(array $input): array
    {
        return array_map(function (mixed $value): mixed {
            if (is_array($value)) {
                return $this->mapDeep($value);
            }

            if (! is_string($value)) {
                return $value;
            }

            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }, $input);
    }
}
