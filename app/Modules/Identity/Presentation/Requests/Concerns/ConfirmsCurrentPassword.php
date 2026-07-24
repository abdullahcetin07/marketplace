<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests\Concerns;

use Closure;
use Illuminate\Support\Facades\Hash;

/**
 * A validation rule that checks a `current_password` field against the
 * authenticated actor.
 *
 * Shared by every self-service action that must re-prove the password before
 * a sensitive change — password change, 2FA disable, recovery-code regenerate.
 * A hijacked session must not be able to make these changes on knowledge it
 * does not have.
 *
 * NOT Laravel's built-in `current_password` rule: that resolves the DEFAULT
 * guard and would misfire for a seller or admin, the same reason
 * `auth()->user()` is banned here. This checks `current_actor()`, which is
 * guard-correct.
 */
trait ConfirmsCurrentPassword
{
    /**
     * @return array<int, mixed>
     */
    protected function currentPasswordRules(): array
    {
        return [
            'required',
            'string',
            function (string $attribute, mixed $value, Closure $fail): void {
                $actor = current_actor();

                if ($actor === null || ! Hash::check((string) $value, $actor->password)) {
                    // Vague on purpose — do not confirm which part was wrong.
                    $fail(__('errors.invalid_credentials'));
                }
            },
        ];
    }
}
