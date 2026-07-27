<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource\Pages;

use App\Modules\Organization\Application\Actions\RegisterOrganizationAction;
use App\Modules\Organization\Domain\DTOs\RegisterOrganizationDTO;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Register a company — the seller's first onboarding step.
 *
 * The record creation is HANDED OFF to `RegisterOrganizationAction`, never to
 * Filament's default mass-assignment: the action is what resolves the locale
 * codes, seats the owner as the first member and announces `OrganizationCreated`
 * after commit. Doing it here would silently produce an ownerless organization
 * with no membership row.
 *
 * @see App\Modules\Organization\Presentation\Controllers\Api\OrganizationController::store()
 */
final class CreateOrganization extends CreateRecord
{
    protected static string $resource = OrganizationResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('organization.registered');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(RegisterOrganizationAction::class)->run(new RegisterOrganizationDTO(
            // The owner is the acting seller — never a field on the form, or a
            // seller could seat someone else as owner of their company.
            ownerId: (int) auth()->id(),
            legalName: (string) $data['legal_name'],
            displayName: $data['display_name'] ?? null,
            slug: (string) $data['slug'],
            countryCode: (string) $data['country_code'],
            currencyCode: (string) $data['currency_code'],
            planSlug: $data['plan_slug'] ?? null,
        ));
    }
}
