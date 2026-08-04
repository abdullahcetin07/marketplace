<?php

declare(strict_types=1);

namespace App\Modules\Settings\Presentation\Policies;

use App\Core\Presentation\Policies\BasePolicy;
use App\Models\User;
use App\Modules\Settings\Domain\Models\Setting;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends BasePolicy<Setting>
 */
final class SettingPolicy extends BasePolicy
{
    /**
     * Two extra gates on top of `setting.update`:
     *
     *  - a locked setting is never editable by anyone, because code reads it
     *    by key and renaming it is a runtime failure;
     *  - Security, System and Performance groups additionally require
     *    `setting.manage_restricted`, so an Editor or Support operator with
     *    general settings access cannot change session lifetimes or take the
     *    platform into maintenance mode.
     *
     * @param Setting $model
     */
    public function update(User $user, Model $model): Response
    {
        if (! $model->isEditable()) {
            return Response::deny(__('errors.setting_locked'));
        }

        if ($model->group->isRestricted()
            && ! $user->hasPermissionTo('setting.manage_restricted', $user->guardName())) {
            return Response::deny(__('errors.missing_permission', [
                'permission' => 'setting.manage_restricted',
            ]));
        }

        return parent::update($user, $model);
    }

    /**
     * Settings are seeded infrastructure, not user content. Creating one at
     * runtime produces a key no code reads; deleting one breaks the code that
     * does. Both are refused outright — modules register settings through
     * SettingsService::register() at boot.
     *
     * @param Setting $model
     */
    public function delete(User $user, Model $model): Response
    {
        return Response::deny(__('errors.setting_undeletable'));
    }

    public function create(User $user): Response
    {
        return Response::deny(__('errors.setting_uncreatable'));
    }

    protected function permissionPrefix(): string
    {
        return 'setting';
    }
}
