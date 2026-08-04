<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Models\User;
use App\Modules\Identity\Domain\Events\SessionRevoked;
use App\Modules\Identity\Domain\Models\UserDevice;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Forget a device — and end its access.
 *
 * "FORGET" MUST REVOKE, NOT JUST DELETE THE ROW. A user who does not recognise
 * a device is saying "this is not me". Deleting the device while its sessions
 * stay live would tell them the threat is gone while the attacker keeps working
 * — the same lie that makes a revoked-but-still-valid session the worst failure
 * mode in this module.
 *
 * So: revoke every session on the device, destroy the underlying framework
 * sessions and tokens, then delete the device row. One transaction.
 *
 * The device's session rows have `device_id` set to null on delete by the
 * schema, so this action must revoke them BEFORE deleting the device — after,
 * they would no longer be findable by device.
 */
final class ForgetDeviceAction extends BaseAction
{
    /**
     * Manages its own transaction below, around the revoke-then-delete unit.
     */
    protected bool $useTransaction = false;

    /**
     * Revoked session UUIDs, captured for the after-commit event.
     *
     * @var array<int, string>
     */
    private array $revokedUuids = [];

    /**
     * @param UserDevice $arguments [0]
     * @param User $arguments [1] the acting user (the owner)
     */
    public function handle(mixed ...$arguments): mixed
    {
        /** @var UserDevice $device */
        $device = $arguments[0];
        /** @var User $actor */
        $actor = $arguments[1];

        if ($device->user_id !== $actor->getKey()) {
            throw new DomainException('A device can only be forgotten by its owner.');
        }

        $sessions = $device->sessions()->whereNull('revoked_at')->get();
        $revokedUuids = [];

        DB::transaction(function () use ($device, $sessions, &$revokedUuids): void {
            foreach ($sessions as $session) {
                $session->markRevoked('device_forgotten');
                $this->destroyUnderlying($session->session_id, $session->token_id);
                $revokedUuids[] = $session->uuid;
            }

            $device->delete();
        });

        $this->revokedUuids = $revokedUuids;

        return null;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var User $actor */
        $actor = $arguments[1];

        if ($this->revokedUuids === []) {
            return;
        }

        SessionRevoked::dispatch(
            $actor->getKey(),
            $actor->uuid,
            $this->revokedUuids,
            'device_forgotten',
            null,
            $actor->type->value,
        );
    }

    /**
     * Destroy whatever actually grants access — the framework session row and
     * the Sanctum token. Mirrors SessionService::destroyUnderlying(); kept
     * local so this action does not reach across into that service's internals.
     */
    private function destroyUnderlying(?string $sessionId, ?int $tokenId): void
    {
        if ($sessionId !== null) {
            DB::table(config('session.table', 'sessions'))->where('id', $sessionId)->delete();
        }

        if ($tokenId !== null) {
            DB::table('personal_access_tokens')->where('id', $tokenId)->delete();
        }
    }
}
