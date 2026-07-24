<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Identity\Domain\Contracts\UserRepositoryContract;
use App\Modules\Identity\Domain\DTOs\ResendVerificationDTO;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resend a verification email.
 *
 * NON-DISCLOSING, exactly like the forgot-password flow (ADR-025). Returns void,
 * never throws for an unknown address, and sends only when the account exists
 * AND is still unverified. An already-verified account produces nothing — but
 * the caller cannot tell that apart from an unknown address, because the
 * response is identical.
 *
 * No transaction: the only effect is a queued notification.
 */
final class ResendVerificationAction extends BaseAction
{
    protected bool $useTransaction = false;

    public function __construct(private readonly UserRepositoryContract $users) {}

    public function handle(mixed ...$arguments): mixed
    {
        /** @var ResendVerificationDTO $data */
        $data = $arguments[0];

        $user = $this->users->findByEmailForType($data->normalisedEmail(), $data->type);

        // Unknown, or already verified — send nothing, say nothing.
        if ($user === null || $user->hasVerifiedEmail()) {
            $this->log($data, $user?->getKey(), skipped: true);

            return null;
        }

        try {
            // Routes through User::sendEmailVerificationNotification(), the same
            // single path registration uses.
            $user->sendEmailVerificationNotification();

            $this->log($data, $user->getKey(), skipped: false);
        } catch (Throwable $e) {
            // A mail failure must not become a 500 that reveals the account.
            report($e);
        }
    }

    private function log(ResendVerificationDTO $data, ?int $userId, bool $skipped): void
    {
        Log::channel('audit')->info('Verification email resend requested', [
            'email' => $data->normalisedEmail(),
            'guard' => $data->type->guard(),
            'user_id' => $userId,
            'sent' => ! $skipped,
            'ip' => request()->ip(),
            'correlation_id' => correlation_id() ?: null,
        ]);
    }
}
