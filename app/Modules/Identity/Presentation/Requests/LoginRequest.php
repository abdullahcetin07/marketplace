<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Identity\Domain\DTOs\LoginDTO;
use App\Shared\Enums\UserType;

/**
 * Login input.
 *
 * NOTE THERE IS NO PASSWORD COMPLEXITY RULE HERE. Validating the *format* of a
 * submitted password on login is a subtle information leak — it tells an
 * attacker which candidates are worth trying — and it locks out users whose
 * password predates a policy change. Complexity belongs on registration and
 * change, never on login.
 */
final class LoginRequest extends BaseRequest
{
    protected ?string $dto = LoginDTO::class;

    /**
     * Anyone may attempt to log in. The rate limiter on the route is what
     * bounds it, not authorisation.
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
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string'],
            // Which guard to authenticate against. Admin is excluded: the
            // admin panel authenticates through Filament's session flow, and
            // exposing an admin login on the public API widens the credential
            // -stuffing surface for no benefit.
            'type' => ['required', 'string', 'in:seller,customer'],
            'remember' => ['sometimes', 'boolean'],
            // Only present on the second leg of a 2FA challenge.
            'two_factor_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'trust_device' => ['sometimes', 'boolean'],
        ];
    }

    public function toDto(): LoginDTO
    {
        return new LoginDTO(
            email: (string) $this->validated('email'),
            password: (string) $this->validated('password'),
            type: UserType::from((string) $this->validated('type')),
            remember: (bool) $this->boolean('remember'),
            twoFactorCode: $this->validated('two_factor_code'),
            trustDevice: (bool) $this->boolean('trust_device'),
            ipAddress: $this->ip(),
            userAgent: $this->userAgent(),
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.in' => __('errors.invalid_credentials'),
        ];
    }
}
