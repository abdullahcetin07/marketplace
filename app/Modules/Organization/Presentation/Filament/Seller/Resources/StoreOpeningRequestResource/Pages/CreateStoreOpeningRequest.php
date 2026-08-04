<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Seller\Resources\StoreOpeningRequestResource\Pages;

use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\StoreOpeningRequestResource;
use App\Modules\Organization\Presentation\Filament\Seller\Support\StoreProposal;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Draft a store-opening request.
 *
 * Creation goes through `CreateStoreOpeningRequestAction`, which makes a DRAFT —
 * nothing is submitted, no limit is checked and no store is created. Submitting
 * is a separate, deliberate step from the list.
 *
 * @see App\Modules\Organization\Presentation\Controllers\Api\StoreRequestController::store()
 */
final class CreateStoreOpeningRequest extends CreateRecord
{
    protected static string $resource = StoreOpeningRequestResource::class;

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('organization.store_request.created');
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $organization = Organization::query()->findOrFail($data['organization_id']);

        /*
        | The select is already limited to companies the actor may request for,
        | but the posted id is client input: re-check the capability here so a
        | tampered form cannot raise a request against someone else's company.
        | Denial is a validation error on the field, not a 403 page — the field
        | is the thing that is wrong.
        */
        if (auth()->user()?->can('createStoreRequest', $organization) !== true) {
            throw ValidationException::withMessages([
                'data.organization_id' => __('errors.forbidden'),
            ]);
        }

        /*
        | Through the SAME helper the onboarding form uses, so the two entry
        | points cannot behave differently. It raises a DRAFT: composing a
        | request is not submitting it, and sending it on stays the deliberate
        | second step this page was always built around.
        |
        | The field names differ only because the onboarding form shares a
        | payload with the organization's own fields and had to prefix them.
        */
        return StoreProposal::request($organization, [
            'store_name' => $data['store_name'],
            'store_slug' => $data['slug'],
            'store_description' => $data['description'] ?? null,
            'reason' => $data['reason'] ?? null,
        ]);
    }
}
