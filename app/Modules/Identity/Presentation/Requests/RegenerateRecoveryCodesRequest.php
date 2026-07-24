<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Identity\Presentation\Requests\Concerns\ConfirmsCurrentPassword;

/**
 * Regenerate recovery codes — requires the current password.
 *
 * Regenerating invalidates the old set. On a hijacked session that would let an
 * attacker mint themselves a fresh set of bypass codes and lock the owner out,
 * so it re-proves the password.
 */
final class RegenerateRecoveryCodesRequest extends BaseRequest
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
