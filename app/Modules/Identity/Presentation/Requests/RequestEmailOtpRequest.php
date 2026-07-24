<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Identity\Domain\DTOs\TwoFactorChallengeDTO;
use App\Shared\Enums\UserType;

/**
 * Request an email-OTP fallback during a login challenge.
 *
 * PUBLIC — the caller is between the two legs of login and holds no session.
 * Carries the same credentials as login; the action re-proves them so this
 * discloses nothing beyond the login it accompanies. No `exists` rule on the
 * email, for the usual enumeration reason.
 */
final class RequestEmailOtpRequest extends BaseRequest
{
    protected ?string $dto = TwoFactorChallengeDTO::class;

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
            'password' => ['required', 'string'],
            'type' => ['required', 'string', 'in:seller,customer'],
        ];
    }

    public function toDto(): TwoFactorChallengeDTO
    {
        return new TwoFactorChallengeDTO(
            email: (string) $this->validated('email'),
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
