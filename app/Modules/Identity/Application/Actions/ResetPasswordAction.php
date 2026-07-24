<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Models\User;
use App\Modules\Identity\Application\Services\SessionService;
use App\Modules\Identity\Domain\DTOs\ResetPasswordDTO;
use App\Modules\Identity\Domain\Events\PasswordChanged;
use App\Modules\Identity\Domain\Events\UserLoggedOut;
use App\Modules\Identity\Domain\Exceptions\PasswordResetFailed;
use App\Modules\Identity\Infrastructure\Notifications\PasswordChangedNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

/**
 * Redeem a reset token and set a new password.
 *
 * WHAT MAKES THIS DIFFERENT FROM A VOLUNTARY CHANGE:
 * the user is NOT authenticated. There is no current session to preserve, so
 * **every** session is revoked — where `ChangePasswordAction` deliberately
 * keeps the acting one alive.
 *
 * Laravel's broker gives three of the required guarantees for free, and they
 * are worth naming because they are easy to assume and easy to lose:
 *
 *  - the token is verified against the email, not accepted alone;
 *  - it is **deleted on success**, so it is single-use;
 *  - it is rejected once past the broker's expiry (15 min for admins).
 *
 * On top of that this action clears `remember_token` — a cookie issued under
 * the old password would otherwise survive the reset and leave the revocation
 * with a hole in it.
 *
 * @see docs/authentication.md
 */
final class ResetPasswordAction extends BaseAction
{
    public function __construct(private readonly SessionService $sessions) {}

    /**
     * @throws PasswordResetFailed
     */
    public function handle(mixed ...$arguments): User
    {
        /** @var ResetPasswordDTO $data */
        $data = $arguments[0];

        $resetUser = null;

        $status = Password::broker($data->broker())->reset(
            [
                'email' => $data->normalisedEmail(),
                'password' => $data->password,
                'password_confirmation' => $data->password,
                'token' => $data->token,
            ],
            function (User $user, string $password) use (&$resetUser): void {
                $user->forceFill([
                    // The 'hashed' cast handles hashing.
                    'password' => $password,
                    'password_changed_at' => now(),
                    // Any "remember me" cookie issued under the old password
                    // must stop working.
                    'remember_token' => null,
                ])->save();

                $resetUser = $user;

                // Laravel's own event — fires the framework's listeners.
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET || ! $resetUser instanceof User) {
            $this->logAudit($data, null, $status);

            throw PasswordResetFailed::invalidToken();
        }

        /*
        | Revoke EVERYTHING. No session is preserved: the user reset because
        | they lost control of the account, and leaving the attacker's session
        | alive would defeat the entire exercise.
        */
        $revoked = $this->sessions->revokeAll(
            user: $resetUser,
            reason: UserLoggedOut::REASON_PASSWORD_CHANGED,
            exceptSessionId: null,
        );

        $this->logAudit($data, $resetUser->getKey(), $status, $revoked);

        return $resetUser;
    }

    /**
     * After commit — the notification must not fire on a rolled-back reset.
     */
    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var User $result */
        $result->notify(new PasswordChangedNotification(viaReset: true));

        PasswordChanged::dispatch(
            $result->getKey(),
            $result->uuid,
            viaReset: true,
            keepSessionId: null,
            guard: $result->type->value,
        );
    }

    private function logAudit(ResetPasswordDTO $data, ?int $userId, string $status, int $revoked = 0): void
    {
        Log::channel('audit')->info('Password reset redeemed', [
            'email' => $data->normalisedEmail(),
            'guard' => $data->type->guard(),
            'user_id' => $userId,
            'successful' => $status === Password::PASSWORD_RESET,
            'broker_status' => $status,
            'sessions_revoked' => $revoked,
            'ip' => request()->ip(),
            'correlation_id' => correlation_id() ?: null,
        ]);
    }
}
