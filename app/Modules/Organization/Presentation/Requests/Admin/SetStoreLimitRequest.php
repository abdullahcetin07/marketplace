<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Requests\Admin;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Organization\Domain\Models\Organization;
use Illuminate\Validation\Rule;

/**
 * Admin sets an organization's store-limit override and/or plan (ADR-028).
 * `override` null clears the bespoke grant. Authorised by
 * OrganizationPolicy::manageLimit.
 */
final class SetStoreLimitRequest extends BaseRequest
{
    public function authorize(): bool
    {
        $org = $this->route('organization');

        return $org instanceof Organization && $this->actor()?->can('manageLimit', $org) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'override' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'plan_id' => ['sometimes', 'nullable', 'integer', Rule::exists('organization_plans', 'id')],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
