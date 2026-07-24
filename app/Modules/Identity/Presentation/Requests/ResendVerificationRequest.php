<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Identity\Domain\DTOs\ResendVerificationDTO;
use App\Shared\Enums\UserType;

/**
 * "Send me the verification email again."
 *
 * Like forgot-password: email + type, NO `exists` rule (that would leak
 * existence), open to unauthenticated callers because a just-registered account
 * is not signed in.
 */
final class ResendVerificationRequest extends BaseRequest
{
    protected ?string $dto = ResendVerificationDTO::class;

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
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'type' => ['required', 'string', 'in:admin,seller,customer'],
        ];
    }

    public function toDto(): ResendVerificationDTO
    {
        return new ResendVerificationDTO(
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
