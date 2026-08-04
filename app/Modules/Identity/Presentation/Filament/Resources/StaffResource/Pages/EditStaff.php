<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Filament\Resources\StaffResource\Pages;

use App\Core\Domain\Context\AuditContext;
use App\Models\User;
use App\Modules\Identity\Application\Actions\AdminUpdateUserAction;
use App\Modules\Identity\Domain\DTOs\AdminUpdateUserDTO;
use App\Modules\Identity\Presentation\Filament\Resources\StaffResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

/**
 * Editing a colleague's account: profile, status, and the roles they hold.
 *
 * THE SAVE ROUTES THROUGH THE ACTION, not a raw Eloquent update.
 * `AdminUpdateUserAction` owns the PATCH semantics, the audit entry, the reason
 * capture and the concrete-subclass resolution — exactly as the API's
 * controller does.
 *
 * ROLES ARE A SECOND, SEPARATELY AUTHORISED WRITE. They are not part of
 * `AdminUpdateUserDTO` and must not be: granting a role is a different power
 * from editing a name, and it has its own policy ability (`assignRoles`) and its
 * own escalation guard. The sync therefore runs only when the role set actually
 * changed, and only after both `UserPolicy::assignRoles` and
 * `StaffResource::assertRolesGrantable()` have agreed.
 */
final class EditStaff extends EditRecord
{
    protected static string $resource = StaffResource::class;

    /**
     * Roles are a relation, not an attribute, so Filament does not fill them.
     * Queried explicitly — reading `$record->roles` would be a lazy load, which
     * throws under strict mode.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $record */
        $record = $this->getRecord();

        $data['roles'] = $record->roles()->pluck('name')->all();

        return $data;
    }

    /**
     * @param User $record
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        // `reason` and `roles` are form fields, not columns — pull them out;
        // everything else present becomes the PATCH.
        $reason = $data['reason'] ?? null;
        $roles = $data['roles'] ?? null;
        unset($data['reason'], $data['roles']);

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

        $updated = app(AdminUpdateUserAction::class)->run($record, $dto);

        if (is_array($roles)) {
            $this->syncRoles($updated, $roles, is_string($reason) && $reason !== '' ? $reason : null);
        }

        return $updated;
    }

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
        ];
    }

    /**
     * Grant and revoke, once, and only if something changed.
     *
     * Two gates, both required: `assignRoles` (which carries the super-admin
     * escalation guard against the TARGET) and `assertRolesGrantable()` (which
     * carries it against the ROLE being granted). Neither subsumes the other —
     * the first stops an Admin editing the platform owner, the second stops an
     * Admin promoting anyone, including themselves, to the platform owner.
     *
     * @param array<int, string> $roles
     *
     * @throws AuthorizationException
     */
    private function syncRoles(User $record, array $roles, ?string $reason): void
    {
        /** @var array<int, string> $requested */
        $requested = array_values(array_filter(
            $roles,
            static fn (mixed $role): bool => is_string($role) && $role !== '',
        ));

        $current = $record->roles()->pluck('name')->all();

        sort($requested);
        sort($current);

        if ($requested === $current) {
            return;
        }

        if (auth()->user()?->can('assignRoles', $record) !== true) {
            throw new AuthorizationException(__('errors.forbidden'));
        }

        StaffResource::assertRolesGrantable($requested);

        // A role that is being REMOVED must also be within the actor's level —
        // otherwise a plain Admin could strip the super-admin role off an
        // account they are not allowed to promote to it.
        StaffResource::assertRolesGrantable(array_values(array_diff($current, $requested)));

        AuditContext::withReasonFor(
            $reason,
            static fn () => $record->syncRoles($requested),
        );
    }
}
