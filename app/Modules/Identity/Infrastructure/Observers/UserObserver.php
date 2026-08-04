<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Observers;

use App\Core\Infrastructure\Observers\BaseObserver;
use App\Models\User;
use App\Modules\Identity\Domain\Events\UserDeleted;
use App\Modules\Identity\Domain\Events\UserUpdated;
use Illuminate\Database\Eloquent\Model;

/**
 * Announces account lifecycle changes as domain events.
 *
 * WHY AN OBSERVER RATHER THAN DISPATCHING FROM THE ACTIONS: a user record is
 * modified from many places — the profile endpoint, the admin panel, a console
 * command, a support tool. Dispatching from each one guarantees some path
 * eventually forgets, and the event's whole value is that it fires for *every*
 * change.
 *
 * `UserCreated` is the deliberate exception: it is dispatched by
 * RegisterUserAction because it needs registration context (which role, which
 * flow) that an observer cannot see.
 *
 * Nothing here contains business rules — an observer fires on seeders and
 * imports too, and its failure modes are invisible at the call site.
 *
 * @see App\Core\Infrastructure\Observers\BaseObserver
 *
 * @extends BaseObserver<User>
 */
final class UserObserver extends BaseObserver
{
    /**
     * @param User $model
     */
    public function updated(Model $model): void
    {
        $changed = array_keys($model->getChanges());

        // A login stamp is not a profile change. recordLogin() uses
        // saveQuietly() so it should not reach here at all — this is the
        // backstop for anything else that touches only these columns.
        $ignored = ['last_login_at', 'last_login_ip', 'login_count', 'updated_at', 'remember_token'];
        $meaningful = array_values(array_diff($changed, $ignored));

        if ($meaningful === []) {
            return;
        }

        // Attribute NAMES only, never values. The values are already captured
        // with full before/after detail by the Audit trail, and duplicating
        // them here would put personal data into a second retention regime.
        UserUpdated::dispatch($model->getKey(), $model->uuid, $meaningful);

        parent::updated($model);
    }

    /**
     * @param User $model
     */
    public function deleted(Model $model): void
    {
        // Soft delete — recoverable. Listeners that purge downstream data must
        // act only on forceDeleted, or a restore leaves the account working
        // but unfindable.
        UserDeleted::dispatch($model->getKey(), $model->uuid, permanent: false);

        parent::deleted($model);
    }

    /**
     * @param User $model
     */
    public function forceDeleted(Model $model): void
    {
        UserDeleted::dispatch($model->getKey(), $model->uuid, permanent: true);

        parent::forceDeleted($model);
    }
}
