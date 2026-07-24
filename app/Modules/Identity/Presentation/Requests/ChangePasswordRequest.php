<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Identity\Presentation\Requests\Concerns\ConfirmsCurrentPassword;
use App\Shared\Rules\StrongPassword;

/**
 * Self-service password change.
 *
 * CURRENT PASSWORD IS REQUIRED AND VERIFIED HERE. A voluntary change must prove
 * the actor knows the existing password — otherwise a hijacked session could
 * change it and lock the real owner out. This is the difference between a
 * change (knowledge-based) and a reset (mailbox-based).
 *
 * `current_password` is checked with a closure against `current_actor()`, NOT
 * Laravel's built-in `current_password` rule — that rule resolves the default
 * guard and would misfire for a seller or admin (the same reason
 * `auth()->user()` is banned here).
 */
final class ChangePasswordRequest extends BaseRequest
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
        $type = current_actor()?->type;

        return [
            'current_password' => $this->currentPasswordRules(),
            // Full policy, tiered by actor type — a change sets a new credential,
            // so the same rules as registration apply. `confirmed` pairs it with
            // password_confirmation; the action layer also blocks reuse of the
            // current password.
            'password' => [
                'required',
                'confirmed',
                $type !== null ? StrongPassword::for($type) : StrongPassword::default(),
            ],
        ];
    }
}
