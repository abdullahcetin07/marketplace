<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Identity\Presentation\Requests\Concerns\ConfirmsCurrentPassword;

/**
 * Disable 2FA — requires the current password.
 *
 * Turning off a second factor is exactly what an attacker on a hijacked session
 * would do, so it must re-prove the password. Clearing 2FA on session strength
 * alone would make the factor worthless.
 */
final class DisableTwoFactorRequest extends BaseRequest
{
    use ConfirmsCurrentPassword;

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
            'current_password' => $this->currentPasswordRules(),
        ];
    }
}
