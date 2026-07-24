<?php

declare(strict_types=1);

namespace App\Modules\Localization\Presentation\Policies;

use App\Core\Presentation\Policies\BasePolicy;
use App\Models\User;
use App\Modules\Localization\Domain\Models\Currency;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends BasePolicy<Currency>
 */
final class CurrencyPolicy extends BasePolicy
{
    /**
     * @param  Currency  $model
     */
    public function delete(User $user, Model $model): Response
    {
        if ($model->is_default) {
            return Response::deny(__('errors.default_currency_undeletable'));
        }

        return parent::delete($user, $model);
    }

    /**
     * Refreshing exchange rates is separated from `currency.update` on purpose:
     * a Finance role should be able to correct a rate without also being able
     * to rename currencies or change their decimal precision.
     */
    public function updateRates(User $user): Response
    {
        return $user->hasPermissionTo('currency.update_rates', $user->guardName())
            ? Response::allow()
            : Response::deny(__('errors.missing_permission', ['permission' => 'currency.update_rates']));
    }

    protected function permissionPrefix(): string
    {
        return 'currency';
    }
}
