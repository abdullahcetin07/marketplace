<?php

declare(strict_types=1);

namespace App\Modules\Localization\Presentation\Policies;

use App\Core\Presentation\Policies\BasePolicy;
use App\Models\User;
use App\Modules\Localization\Domain\Models\Translation;
use Illuminate\Auth\Access\Response;

/**
 * @extends BasePolicy<Translation>
 */
final class TranslationPolicy extends BasePolicy
{
    public function import(User $user): Response
    {
        return $this->ability($user, 'translation.import');
    }

    public function export(User $user): Response
    {
        return $this->ability($user, 'translation.export');
    }

    protected function permissionPrefix(): string
    {
        return 'translation';
    }

    private function ability(User $user, string $permission): Response
    {
        return $user->hasPermissionTo($permission, $user->guardName())
            ? Response::allow()
            : Response::deny(__('errors.missing_permission', ['permission' => $permission]));
    }
}
