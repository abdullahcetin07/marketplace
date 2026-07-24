<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Models\User;
use App\Modules\Identity\Domain\Events\UserLoggedOut;
use App\Modules\Identity\Domain\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * End the current session.
 *
 * Order matters and is not interchangeable: the session row is marked revoked
 * BEFORE the framework session is invalidated, because invalidating first
 * discards the session id needed to find the row.
 */
final class LogoutAction extends BaseAction
{
    public function handle(mixed ...$arguments): void
    {
        /** @var User $user */
        /** @var Request $request */
        [$user, $request] = [$arguments[0], $arguments[1]];
        $guard = $arguments[2] ?? $user->guardName();
        $reason = $arguments[3] ?? UserLoggedOut::REASON_MANUAL;

        $this->closeSessionRow($user, $request, $guard, $reason);

        // API token sessions: delete the token that authorised this request.
        $token = $user->currentAccessToken();

        if ($token !== null) {
            DB::table('personal_access_tokens')->where('id', $token->getKey())->delete();
        }

        Auth::guard($guard)->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var User $user */
        $user = $arguments[0];
        $guard = $arguments[2] ?? $user->guardName();
        $reason = $arguments[3] ?? UserLoggedOut::REASON_MANUAL;

        UserLoggedOut::dispatch($user->getKey(), $user->uuid, $guard, $reason);
    }

    private function closeSessionRow(User $user, Request $request, string $guard, string $reason): void
    {
        if (! $request->hasSession()) {
            return;
        }

        UserSession::query()
            ->where('user_id', $user->getKey())
            ->where('guard', $guard)
            ->where('session_id', $request->session()->getId())
            ->whereNull('revoked_at')
            ->first()
            ?->markRevoked($reason);
    }
}
