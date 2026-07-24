<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Localization\Domain\Contracts\CountryRepositoryContract;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Organization\Domain\Contracts\OrganizationPlanRepositoryContract;
use App\Modules\Organization\Domain\DTOs\RegisterOrganizationDTO;
use App\Modules\Organization\Domain\Enums\OrganizationMemberStatus;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Enums\OrganizationStatus;
use App\Modules\Organization\Domain\Events\OrganizationCreated;
use App\Modules\Organization\Domain\Events\OrganizationMemberJoined;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;

/**
 * Register a new legal company — the seller's first step.
 *
 * The organization starts **Pending**: it is not operational and cannot open a
 * store until an admin approves its KYC (ADR-028, §3.4). Locale codes resolve to
 * ids through the Localization repositories (the one permitted cross-module
 * dependency); an unknown code was already rejected by validation.
 *
 * The create is a model diff, so the Auditable trait records it. The announce
 * fires after commit, so nothing reacts to an org that rolled back.
 */
final class RegisterOrganizationAction extends BaseAction
{
    public function __construct(
        private readonly CountryRepositoryContract $countries,
        private readonly CurrencyRepositoryContract $currencies,
        private readonly OrganizationPlanRepositoryContract $plans,
    ) {}

    public function handle(mixed ...$arguments): Organization
    {
        /** @var RegisterOrganizationDTO $data */
        $data = $arguments[0];

        $organization = Organization::query()->create([
            'owner_id' => $data->ownerId,
            'legal_name' => $data->legalName,
            'display_name' => $data->displayName,
            'slug' => $data->slug,
            'status' => OrganizationStatus::Pending,
            'plan_id' => $data->planSlug === null
                ? null
                : $this->plans->findBySlug($data->planSlug)?->getKey(),
            'country_id' => $this->countries->findByIso2($data->countryCode)?->getKey(),
            'currency_id' => $this->currencies->findByCode($data->currencyCode)?->getKey(),
        ]);

        // The owner is a member from the first moment (ADR-029/030): `owner_id`
        // is the canonical pointer, and this row makes the Owner part of the
        // membership model, so the capability matrix and isolation apply
        // uniformly. Same transaction as the org create (BaseAction default).
        OrganizationMember::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $data->ownerId,
            'role' => OrganizationRole::Owner,
            'status' => OrganizationMemberStatus::Active,
            'joined_at' => now(),
        ]);

        return $organization;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Organization $result */
        OrganizationCreated::dispatch($result->getKey(), $result->uuid, $result->owner_id);
        OrganizationMemberJoined::dispatch(
            $result->getKey(),
            $result->uuid,
            $result->owner_id,
            OrganizationRole::Owner->value,
        );
    }
}
