<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Identity\Application\Services\TwoFactorService;
use App\Modules\Identity\Domain\Contracts\UserRepositoryContract;
use App\Modules\Identity\Domain\DTOs\TwoFactorChallengeDTO;
use App\Modules\Identity\Infrastructure\Notifications\EmailOtpNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Email a one-time fallback code, mid-login.
 *
 * NON-DISCLOSING, and for two reasons at once:
 *
 *  - like forgot-password, the response is identical whether or not the account
 *    exists (ADR-025);
 *  - and it re-verifies the password with the same dummy-hash timing defence as
 *    login, so it leaks nothing the login leg before it did not already.
 *
 * A code is issued ONLY when: the account exists, the password is correct, and
 * 2FA is actually enabled. Email OTP is a *fallback* for a user who has 2FA but
 * cannot reach their authenticator — never a way to add a factor that was not
 * set up.
 *
 * Returns void; no transaction (the only effect is a cache write and a queued
 * mail).
 */
final class RequestEmailOtpAction extends BaseAction
{
    protected bool $useTransaction = false;

    /** A valid bcrypt hash nothing matches — burns equal time on a miss. */
    private const string DUMMY_HASH = '$2y$12$sJ8Q4nqXH0jbYjJ0eKQ0uOaG3H1s0YQ5jP5nZ8wQ7yG4L1mN2oP6a';

    public function __construct(
        private readonly UserRepositoryContract $users,
        private readonly TwoFactorService $twoFactor,
    ) {}

    public function handle(mixed ...$arguments): void
    {
        /** @var TwoFactorChallengeDTO $data */
        $data = $arguments[0];

        $user = $this->users->findByEmailForType($data->normalisedEmail(), $data->type);

        // Always run one bcrypt comparison, so a missing account and a wrong
        // password take the same time — no enumeration by latency.
        $passwordOk = Hash::check($data->password, $user?->password ?? self::DUMMY_HASH);

        if ($user === null || ! $passwordOk || ! $user->hasTwoFactorEnabled()) {
            $this->log($data, $user?->getKey(), issued: false);

            return;
        }

        try {
            $code = $this->twoFactor->issueEmailOtp($user);

            $user->notify(new EmailOtpNotification($code));

            $this->log($data, $user->getKey(), issued: true);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function log(TwoFactorChallengeDTO $data, ?int $userId, bool $issued): void
    {
        Log::channel('audit')->info('Email OTP requested', [
            'email' => $data->normalisedEmail(),
            'guard' => $data->type->guard(),
            'user_id' => $userId,
            'issued' => $issued,
            'ip' => request()->ip(),
            'correlation_id' => correlation_id() ?: null,
        ]);
    }
}
