<?php

declare(strict_types=1);

namespace App\Modules\Localization\Presentation\Policies;

use App\Core\Presentation\Policies\BasePolicy;
use App\Models\User;
use App\Modules\Localization\Domain\Models\Language;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Locale management is admin-only. `owns()` is left at its default of false,
 * which is correct here: BasePolicy allows admins through without an ownership
 * check, and no other actor type holds these permissions at all.
 *
 * @extends BasePolicy<Language>
 */
final class LanguagePolicy extends BasePolicy
{
    /**
     * Deleting the platform default would leave the application with no locale
     * to fall back to on the very next request. The model throws on this too;
     * refusing at the policy layer means the admin UI hides the button rather
     * than showing one that always errors.
     *
     * @param  Language  $model
     */
    public function delete(User $user, Model $model): Response
    {
        if ($model->is_default) {
            return Response::deny(__('errors.default_language_undeletable'));
        }

        return parent::delete($user, $model);
    }

    protected function permissionPrefix(): string
    {
        return 'language';
    }
}
