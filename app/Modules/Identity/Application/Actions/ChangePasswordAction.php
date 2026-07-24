<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Models\User;
use App\Modules\Identity\Application\Services\SessionService;
use App\Modules\Identity\Domain\Events\PasswordChanged;
use App\Modules\Identity\Domain\Events\UserLoggedOut;
use App\Modules\Identity\Infrastructure\Notifications\PasswordChangedNotification;
use Illuminate\Support\Facades\Hash;

/**
 * Change a password and invalidate every other session.
 *
 * THE SESSION CASCADE IS THE POINT. A password change that leaves the
 * attacker's session alive achieves nothing — the whole reason a user changes
 * their password after a compromise is to evict whoever else is in there.
 *
 * The session performing the change is kept alive deliberately: logging the
 * user out of the tab they just used is hostile, and they immediately sign
 * back in, which defeats the purpose.
 */
final class ChangePasswordAction extends BaseAction
{
    public function __construct(private readonly SessionService $sessions) {}

    /**
     * @param  User  $arguments [0]
     * @param  string  $arguments [1] new plaintext password
     * @param  string|null  $arguments [2] session id to keep alive
     * @param  bool  $arguments [3] whether this came from a reset flow
     */
    public function handle(mixed ...$arguments): User
    {
        /** @var User $user */
        $user = $arguments[0];
        $newPassword = (string) $arguments[1];
        $keepSessionId = $arguments[2] ?? null;

        $user->forceFill([
            // The 'hashed' cast handles hashing; forceFill bypasses $fillable,
            // which deliberately excludes password.
            'password' => $newPassword,
            'password_changed_at' => now(),
            // Any "remember me" cookie issued under the old password must stop
            // working, or the cascade below has a hole in it.
            'remember_token' => null,
        ])->save();

        $this->sessions->revokeAll(
            user: $user,
            reason: UserLoggedOut::REASON_PASSWORD_CHANGED,
            exceptSessionId: $keepSessionId,
        );

        return $user;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var User $result */

        // Tell the owner — the point is the case where they did NOT do this.
        // Mirrors ResetPasswordAction, so both write paths notify consistently.
        $result->notify(new PasswordChangedNotification(viaReset: false));

        PasswordChanged::dispatch(
            $result->getKey(),
            $result->uuid,
            (bool) ($arguments[3] ?? false),
            $arguments[2] ?? null,
            $result->type->value,
        );
    }

    /**
     * Reject a no-op change before touching anything.
     *
     * Re-setting the same password would cascade every session for no security
     * benefit — the user would be logged out of their other devices by an
     * action that changed nothing.
     */
    protected function before(mixed ...$arguments): void
    {
        /** @var User $user */
        $user = $arguments[0];

        if (Hash::check((string) $arguments[1], $user->password)) {
            throw new \InvalidArgumentException('The new password must differ from the current one.');
        }
    }
}
