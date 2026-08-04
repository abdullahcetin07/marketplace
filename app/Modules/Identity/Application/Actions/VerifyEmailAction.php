<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Models\User;
use App\Modules\Identity\Domain\Contracts\UserRepositoryContract;
use App\Modules\Identity\Domain\Events\EmailVerified;
use App\Modules\Identity\Domain\Exceptions\EmailVerificationFailed;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Log;

/**
 * Mark an email address verified.
 *
 * SIGNATURE VALIDATION HAPPENS IN THE REQUEST, not here — `VerifyEmailRequest`
 * rejects a tampered or expired link before this action runs. By the time we
 * are called, the signed link is proven authentic; this action's job is to
 * match the hash to the account and flip the flag.
 *
 * IDEMPOTENT. A repeat click on a still-valid link succeeds without re-marking
 * and without re-firing the event. Verification links get clicked twice
 * constantly — a double-click, a prefetch, a forwarded email — and none of
 * that should error or duplicate a timeline entry.
 *
 * @see App\Modules\Identity\Presentation\Requests\VerifyEmailRequest
 */
final class VerifyEmailAction extends BaseAction
{
    public function __construct(private readonly UserRepositoryContract $users) {}

    /**
     * @param string $arguments [0] user UUID from the route
     * @param string $arguments [1] sha1(email) hash from the route
     *
     * @throws EmailVerificationFailed
     */
    public function handle(mixed ...$arguments): User
    {
        $uuid = (string) $arguments[0];
        $hash = (string) $arguments[1];

        $user = $this->users->findByUuid($uuid);

        if ($user === null) {
            throw EmailVerificationFailed::invalidLink();
        }

        /*
        | The hash binds the link to THIS account's email. hash_equals guards
        | against a timing side-channel — not a serious threat here, but the
        | comparison is one line either way and the habit is worth keeping.
        */
        if (! hash_equals(sha1((string) $user->getEmailForVerification()), $hash)) {
            throw EmailVerificationFailed::invalidLink();
        }

        // Already verified: succeed silently, fire nothing. @see class docblock.
        if ($user->hasVerifiedEmail()) {
            return $user;
        }

        $user->markEmailAsVerified();

        $this->log($user, true);

        return $user;
    }

    /**
     * After commit — announce only a genuine first-time verification.
     */
    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var User $result */

        // Guard again: a re-verification reached handle()'s early return and
        // must not announce here either.
        if (! $result->wasChanged('email_verified_at')) {
            return;
        }

        // Laravel's own event, for framework listeners.
        event(new Verified($result));

        EmailVerified::dispatch($result->getKey(), $result->uuid, $result->guardName());
    }

    private function log(User $user, bool $firstTime): void
    {
        Log::channel('audit')->info('Email verified', [
            'user_id' => $user->getKey(),
            'guard' => $user->guardName(),
            'first_time' => $firstTime,
            'correlation_id' => correlation_id() ?: null,
        ]);
    }
}
