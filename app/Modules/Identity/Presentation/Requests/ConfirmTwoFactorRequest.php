<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;

/**
 * Confirm 2FA enrolment with a code from the authenticator.
 *
 * Proving a live TOTP code before the account is protected is the whole point
 * of the two-step enrolment — it guarantees the authenticator actually scanned
 * the secret, so the user cannot lock themselves out.
 */
final class ConfirmTwoFactorRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return current_actor() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 6 digits. Spaces are stripped in prepareForValidation so a code
            // read off a screen with a gap still validates.
            'code' => ['required', 'string', 'digits:6'],
        ];
    }

    public function code(): string
    {
        return (string) $this->validated('code');
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (is_string($this->input('code'))) {
            $this->merge(['code' => preg_replace('/\s+/', '', (string) $this->input('code'))]);
        }
    }
}
