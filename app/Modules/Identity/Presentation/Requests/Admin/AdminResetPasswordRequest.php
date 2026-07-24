<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests\Admin;

use App\Core\Presentation\Requests\BaseRequest;
use App\Models\User;

/**
 * An administrator triggering a password reset on someone else's account.
 *
 * Authorised through UserPolicy::resetPassword — a distinct permission
 * (`user.reset_password`, held by Support) plus the super-admin guard. The reset
 * LINK is emailed; nothing about it is returned here (ADR-025).
 */
final class AdminResetPasswordRequest extends BaseRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User
            && $this->actor()?->can('resetPassword', $target) === true;
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
