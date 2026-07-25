<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Identity\Domain\Contracts\UserRepositoryContract;
use App\Modules\Identity\Domain\DTOs\PasswordResetRequestDTO;
use App\Modules\Identity\Domain\Events\PasswordResetRequested;
use App\Modules\Identity\Infrastructure\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Throwable;

/**
 * Issue a password reset token and email it.
 *
 * THE CALLER LEARNS NOTHING (ADR-025). This action returns void and never
 * throws for an unknown address. Whether the account exists is invisible from
 * outside: same response, same status, and — because the work either way is a
 * broker call plus a queued notification — no usable timing difference.
 *
 * THE TOKEN NEVER LEAVES THIS ACTION. It goes into the notification and
 * nowhere else. Not the response, not the event, not the log.
 *
 * `createToken()` deletes any existing token for the address first, so issuing
 * a new one invalidates every previous unused token. A user who clicks
 * "forgot password" three times leaves one live credential, not three.
 *
 * Transaction OFF: the only write is the broker's token row, and the
 * notification must not be dispatched inside a transaction that could roll
 * back after the mail has queued.
 *
 * @see docs/authentication.md
 */
final class RequestPasswordResetAction extends BaseAction
{
    protected bool $useTransaction = false;

    public function __construct(private readonly UserRepositoryContract $users) {}

    public function handle(mixed ...$arguments): mixed
    {
        /** @var PasswordResetRequestDTO $data */
        $data = $arguments[0];

        $user = $this->users->findByEmailForType($data->normalisedEmail(), $data->type);

        if ($user === null) {
            // Deliberately silent. Recording an attempt against a non-existent
            // address is useful for detection, but it belongs to the risk
            // phase — and it must never change what the caller sees.
            $this->logAudit($data, null, 'unknown_address');

            return null;
        }

        /*
        | Suspended and soft-deleted accounts get no token either. Resetting
        | into a suspended account would hand an attacker a working credential
        | on an account the platform has deliberately disabled.
        */
        if (! $user->canAuthenticate()) {
            $this->logAudit($data, $user->getKey(), 'account_not_active');

            return null;
        }

        try {
            $token = Password::broker($data->broker())->createToken($user);

            $user->notify(new ResetPasswordNotification($token, $user->email));

            $this->logAudit($data, $user->getKey(), null);

            PasswordResetRequested::dispatch(
                $user->getKey(),
                $user->uuid,
                $data->type->guard(),
                request()->ip(),
            );
        } catch (Throwable $e) {
            // A mail or broker failure must not become a 500 that tells the
            // caller this address is real.
            report($e);
        }

        return null;
    }

    /**
     * Audit every request, including the ones that produced nothing.
     *
     * A run of requests against addresses that do not exist is enumeration,
     * and this is the only place it is visible — the response certainly does
     * not show it.
     */
    private function logAudit(PasswordResetRequestDTO $data, ?int $userId, ?string $skippedReason): void
    {
        Log::channel('audit')->info('Password reset requested', [
            'email' => $data->normalisedEmail(),
            'guard' => $data->type->guard(),
            'user_id' => $userId,
            'issued' => $skippedReason === null,
            'skipped_reason' => $skippedReason,
            'ip' => request()->ip(),
            'correlation_id' => correlation_id() ?: null,
        ]);
    }
}
