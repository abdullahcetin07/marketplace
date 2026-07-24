<?php

declare(strict_types=1);

namespace App\Modules\Localization\Presentation\Policies;

use App\Core\Presentation\Policies\BasePolicy;
use App\Modules\Localization\Domain\Models\Country;

/**
 * @extends BasePolicy<Country>
 */
final class CountryPolicy extends BasePolicy
{
    protected function permissionPrefix(): string
    {
        return 'country';
    }
}
