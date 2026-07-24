<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests\Admin;

use App\Core\Presentation\Requests\BaseRequest;
use App\Models\User;

/**
 * An administrator clearing another user's two-factor authentication.
 *
 * The highest-trust admin action on an account short of impersonation — it is
 * exactly what an attacker with helpdesk access would do — so it is its own
 * permission (`user.disable_two_factor`), guarded against acting on a
 * super-admin, and a `reason` is strongly encouraged for the forensic trail.
 */
final class AdminDisableTwoFactorRequest extends BaseRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User
            && $this->actor()?->can('disableTwoFactor', $target) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
