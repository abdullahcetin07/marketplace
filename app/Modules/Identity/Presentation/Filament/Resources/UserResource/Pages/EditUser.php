<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Filament\Resources\UserResource\Pages;

use App\Models\User;
use App\Modules\Identity\Application\Actions\AdminUpdateUserAction;
use App\Modules\Identity\Domain\DTOs\AdminUpdateUserDTO;
use App\Modules\Identity\Presentation\Filament\Resources\UserResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Editing an account.
 *
 * THE SAVE ROUTES THROUGH THE ACTION, not a raw Eloquent update. That is what
 * keeps this page presentation-only: AdminUpdateUserAction owns the PATCH
 * semantics, the audit entry, the reason capture and the concrete-subclass
 * resolution — exactly as the API's controller does. The panel supplies the
 * form; the action supplies the behaviour. Nothing is duplicated.
 */
final class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @param  User  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        // `reason` is a form field, not a column — pull it out for the audit
        // trail; everything else present becomes the PATCH.
        $reason = $data['reason'] ?? null;
        unset($data['reason']);

        $dto = new AdminUpdateUserDTO(
            firstName: $data['first_name'] ?? null,
            lastName: $data['last_name'] ?? null,
            phone: $data['phone'] ?? null,
            status: $data['status'] ?? null,
            reason: is_string($reason) && $reason !== '' ? $reason : null,
            // Only the keys the form actually submitted count as present.
            present: array_values(array_intersect(
                ['first_name', 'last_name', 'phone', 'status'],
                array_keys($data),
            )),
        );

        return app(AdminUpdateUserAction::class)->run($record, $dto);
    }

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
