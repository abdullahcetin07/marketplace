<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Identity\Domain\DTOs\ResetPasswordDTO;
use App\Shared\Enums\UserType;
use App\Shared\Rules\StrongPassword;

/**
 * Redeem a reset token.
 *
 * The password policy IS enforced here — unlike login, where validating format
 * would leak which candidates are worth trying. A reset sets a new credential,
 * so the same rules as registration apply, tiered by actor type.
 *
 * Token validity is NOT checked here. That belongs to the broker inside
 * `ResetPasswordAction`, so an invalid token produces one indistinguishable
 * `RESET_TOKEN_INVALID` rather than a field-level validation error that hints
 * at which part was wrong.
 */
final class ResetPasswordRequest extends BaseRequest
{
    protected ?string $dto = ResetPasswordDTO::class;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = UserType::tryFrom((string) $this->input('type')) ?? UserType::Customer;

        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', StrongPassword::for($type)],
            'type' => ['required', 'string', 'in:admin,seller,customer'],
        ];
    }

    public function toDto(): ResetPasswordDTO
    {
        return new ResetPasswordDTO(
            email: (string) $this->validated('email'),
            token: (string) $this->validated('token'),
            password: (string) $this->validated('password'),
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
