<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Identity\Domain\DTOs\PasswordResetRequestDTO;
use App\Shared\Enums\UserType;

/**
 * "I forgot my password."
 *
 * NO `exists` RULE ON THE EMAIL — deliberately. A validation error saying "this
 * address is not registered" would be the enumeration oracle the whole flow is
 * built to avoid (ADR-025). An unknown address validates fine and produces the
 * same response as a known one.
 *
 * `type` accepts every actor type per Q3 — one API for all eight roles, with
 * business rules in Identity and the Admin Panel providing UI only.
 */
final class ForgotPasswordRequest extends BaseRequest
{
    protected ?string $dto = PasswordResetRequestDTO::class;

    /**
     * Open, like login. The rate limiter bounds it, not authorisation.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Format only. Never existence.
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'type' => ['required', 'string', 'in:admin,seller,customer'],
        ];
    }

    public function toDto(): PasswordResetRequestDTO
    {
        return new PasswordResetRequestDTO(
            email: (string) $this->validated('email'),
            type: UserType::from((string) $this->validated('type')),
        );
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }
}
