<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource\Pages;

use App\Modules\Organization\Application\Actions\UpdateOrganizationAction;
use App\Modules\Organization\Domain\DTOs\UpdateOrganizationDTO;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Edit the company profile — the two name columns and nothing else.
 *
 * Delegates to `UpdateOrganizationAction` rather than saving the model, so the
 * write is audited and attributed exactly as the API's PATCH is. Status,
 * ownership, plan and limits are changed by their own dedicated actions, never
 * from here.
 *
 * @see App\Modules\Organization\Presentation\Controllers\Api\OrganizationController::update()
 */
final class EditOrganization extends EditRecord
{
    protected static string $resource = OrganizationResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var \App\Modules\Organization\Domain\Models\Organization $record */
        return app(UpdateOrganizationAction::class)->run($record, new UpdateOrganizationDTO(
            legalName: $data['legal_name'] ?? null,
            displayName: $data['display_name'] ?? null,
            // PATCH semantics, exactly like UpdateOrganizationRequest::toDto():
            // only the fields the form actually submitted are written.
            present: array_values(array_intersect(['legal_name', 'display_name'], array_keys($data))),
        ));
    }
}
