<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Seller\Resources;

use App\Modules\Organization\Application\Actions\ChangeMemberRoleAction;
use App\Modules\Organization\Application\Actions\ChangeMemberStatusAction;
use App\Modules\Organization\Application\Actions\InviteMemberAction;
use App\Modules\Organization\Application\Actions\RemoveMemberAction;
use App\Modules\Organization\Domain\Contracts\OrganizationMemberRepositoryContract;
use App\Modules\Organization\Domain\DTOs\InviteMemberDTO;
use App\Modules\Organization\Domain\Enums\OrganizationCapability;
use App\Modules\Organization\Domain\Enums\OrganizationMemberStatus;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\TeamMemberResource\Pages;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Ekip — the seller's OWN team.
 *
 * THIS IS THE COUNTERPART TO THE ADMIN PANEL'S REFUSAL. An operator overseeing
 * a merchant deliberately gets no team controls (Identity's SellerResource);
 * the team belongs to the merchant, and this is where they manage it: invite a
 * colleague, change their role, remove them.
 *
 * MEMBERSHIP-SCOPED (ADR-030). `getEloquentQuery()` is confined to the acting
 * user's own active memberships — the same tenancy wall the other seller
 * resources use — so one seller can never see, let alone touch, another
 * company's team. Every per-row decision is then re-checked by
 * `OrganizationMemberPolicy`, which resolves the actor's capability in the
 * TARGET's organization: two independent walls, not one.
 *
 * ORG ROLES ONLY. The roles offered are `OrganizationRole::assignable()` — the
 * Organization module's own matrix, Owner excluded because ownership is reached
 * only by transfer (ADR-029). Platform staff roles are not reachable from this
 * panel at all; they are granted under Personel in the admin panel and nowhere
 * else.
 *
 * Every write delegates to the module Actions the API uses — invite, change
 * role, remove — so the events, the audit entries and the invitation token
 * semantics come out identical.
 *
 * @see App\Modules\Organization\Presentation\Controllers\Api\MemberController
 * @see App\Modules\Identity\Presentation\Filament\Resources\SellerResource
 */
final class TeamMemberResource extends Resource
{
    protected static ?string $model = OrganizationMember::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'uuid';

    public static function getNavigationGroup(): string
    {
        return __('nav.team');
    }

    public static function getModelLabel(): string
    {
        return __('organization.team.member_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('organization.team.member_plural');
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | WHY canViewAny() IS TRUE. Org capabilities are NOT Spatie permissions
    | (Spatie `teams` is false), so there is no `organization_member.view_any`
    | grant a seller could hold. The panel is seller-guarded, the query below is
    | the tenancy wall, and per-record authorization stays in
    | OrganizationMemberPolicy — the same reasoning as the seller
    | OrganizationResource and StoreResource.
    |
    | A member is invited, never "created" — there is no create page, because a
    | membership row without an invitation is a membership nobody consented to.
    */

    public static function canViewAny(): bool
    {
        return true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // `display_name` is computed, never stored (ADR-012), so it is
                // rendered but not searched — the email below is the searchable
                // handle on a person.
                Tables\Columns\TextColumn::make('user.display_name')
                    ->label(__('organization.team.member_name')),

                Tables\Columns\TextColumn::make('user.email')
                    ->label(__('organization.team.member_email'))
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('organization.legal_name')
                    ->label(__('organization.singular'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('role')
                    ->label(__('organization.team.role'))
                    ->badge()
                    ->color(fn (OrganizationRole $state): string => $state->isOwner() ? 'success' : 'gray')
                    ->formatStateUsing(fn (OrganizationRole $state): string => $state->label()),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('organization.team.status'))
                    ->badge()
                    ->color(fn (OrganizationMemberStatus $state): string => $state === OrganizationMemberStatus::Active ? 'success' : 'danger')
                    ->formatStateUsing(fn (OrganizationMemberStatus $state): string => $state->label()),

                Tables\Columns\TextColumn::make('joined_at')
                    ->label(__('organization.team.joined_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->label(__('organization.team.role'))
                    ->options(fn (): array => OrganizationRole::options()),
            ])
            ->headerActions([
                self::inviteAction(),
            ])
            ->actions([
                self::changeRoleAction(),
                self::deactivateAction(),
                self::reactivateAction(),
                self::removeAction(),
            ])
            // Removing a colleague is a per-row decision with a reason. There is
            // no version of "remove these four" that should be one click.
            ->bulkActions([])
            ->emptyStateIcon('heroicon-o-users')
            ->emptyStateHeading(__('organization.team.empty.heading'))
            ->emptyStateDescription(__('organization.team.empty.description'))
            ->defaultSort('id', 'desc');
    }

    /**
     * THE TENANCY WALL (ADR-030): the acting user's own active memberships and
     * nothing else. Everything the table renders is eager loaded — strict mode
     * makes a lazy load throw.
     *
     * @return Builder<OrganizationMember>
     */
    public static function getEloquentQuery(): Builder
    {
        $ids = app(OrganizationMemberRepositoryContract::class)
            ->organizationIdsForUser((int) auth()->id());

        /** @var Builder<OrganizationMember> $query */
        $query = parent::getEloquentQuery();

        return $query
            ->with(['user', 'organization'])
            ->whereIn('organization_id', $ids);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTeamMembers::route('/'),
        ];
    }

    /**
     * Invite an address to a company the actor may invite to.
     *
     * The organization SELECT is itself scoped: it offers only companies where
     * the actor holds `member.invite`, so a Viewer sees an empty list rather
     * than a form that fails on submit. The action then re-authorises through
     * `OrganizationPolicy::inviteMembers` — the select narrows the choice, the
     * policy is the control.
     */
    private static function inviteAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('invite')
            ->label(__('organization.team.action.invite'))
            ->icon('heroicon-o-envelope')
            ->visible(fn (): bool => self::organizationOptions(OrganizationCapability::MemberInvite) !== [])
            ->form([
                Forms\Components\Select::make('organization')
                    ->label(__('organization.singular'))
                    ->options(fn (): array => self::organizationOptions(OrganizationCapability::MemberInvite))
                    ->required()
                    ->native(false),

                Forms\Components\TextInput::make('email')
                    ->label(__('organization.team.member_email'))
                    ->email()
                    ->required()
                    ->maxLength(255),

                /*
                | Org roles only, Owner excluded — ownership is reached solely
                | by transfer (ADR-029), never by invitation. Platform staff
                | roles are not offered here and never will be.
                */
                Forms\Components\Select::make('role')
                    ->label(__('organization.team.role'))
                    ->options(fn (): array => self::assignableRoleOptions())
                    ->required()
                    ->native(false)
                    ->helperText(__('organization.team.role_help')),
            ])
            ->action(function (array $data): void {
                $organization = static::scopedOrganization((int) $data['organization']);

                if (auth()->user()?->can('inviteMembers', $organization) !== true) {
                    Notification::make()->title(__('errors.forbidden'))->danger()->send();

                    return;
                }

                app(InviteMemberAction::class)->run($organization, new InviteMemberDTO(
                    organizationId: $organization->getKey(),
                    email: (string) $data['email'],
                    role: OrganizationRole::from((string) $data['role']),
                    invitedBy: (int) auth()->id(),
                ));

                Notification::make()->title(__('organization.invitation_sent'))->success()->send();
            });
    }

    /**
     * Change a colleague's role. The Owner's row is not offered the action at
     * all, and `ChangeMemberRoleAction` refuses it again on the way in.
     */
    private static function changeRoleAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('changeRole')
            ->label(__('organization.team.action.change_role'))
            ->icon('heroicon-o-arrows-right-left')
            ->form([
                Forms\Components\Select::make('role')
                    ->label(__('organization.team.role'))
                    ->options(fn (): array => self::assignableRoleOptions())
                    ->required()
                    ->native(false)
                    ->default(fn (OrganizationMember $record): string => $record->role->value),

                Forms\Components\Textarea::make('reason')
                    ->label(__('organization.team.reason'))
                    ->helperText(__('organization.team.reason_help'))
                    ->maxLength(500),
            ])
            ->visible(fn (OrganizationMember $record): bool => ! $record->isOwner()
                && auth()->user()?->can('updateRole', $record) === true)
            ->action(function (OrganizationMember $record, array $data): void {
                app(ChangeMemberRoleAction::class)->run(
                    $record,
                    OrganizationRole::from((string) $data['role']),
                    static::reason($data),
                );

                Notification::make()->title(__('organization.team.role_changed'))->success()->send();
            });
    }

    /**
     * Freeze a membership without ending it (§2.2).
     *
     * THE MIDDLE GROUND BETWEEN A ROLE CHANGE AND A REMOVAL. Somebody on leave,
     * a contractor between engagements, an account under internal review — all
     * of them want "cannot act right now", and removal is the wrong instrument
     * because undoing it costs a fresh invitation and the person's role.
     */
    private static function deactivateAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('deactivate')
            ->label(__('organization.team.action.deactivate'))
            ->icon('heroicon-o-pause-circle')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription(__('organization.team.action.deactivate_confirm'))
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label(__('organization.team.reason'))
                    ->helperText(__('organization.team.reason_help'))
                    ->maxLength(500),
            ])
            ->visible(fn (OrganizationMember $record): bool => ! $record->isOwner()
                && $record->status === OrganizationMemberStatus::Active
                && auth()->user()?->can('changeStatus', $record) === true)
            ->action(function (OrganizationMember $record, array $data): void {
                app(ChangeMemberStatusAction::class)->run(
                    $record,
                    OrganizationMemberStatus::Suspended,
                    static::reason($data),
                );

                Notification::make()->title(__('organization.team.deactivated'))->success()->send();
            });
    }

    private static function reactivateAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('reactivate')
            ->label(__('organization.team.action.reactivate'))
            ->icon('heroicon-o-play-circle')
            ->color('success')
            ->requiresConfirmation()
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label(__('organization.team.reason'))
                    ->maxLength(500),
            ])
            ->visible(fn (OrganizationMember $record): bool => ! $record->isOwner()
                && $record->status === OrganizationMemberStatus::Suspended
                && auth()->user()?->can('changeStatus', $record) === true)
            ->action(function (OrganizationMember $record, array $data): void {
                // The role survived the freeze, so thawing restores exactly the
                // access they had — no re-invitation, no re-assignment.
                app(ChangeMemberStatusAction::class)->run(
                    $record,
                    OrganizationMemberStatus::Active,
                    static::reason($data),
                );

                Notification::make()->title(__('organization.team.reactivated'))->success()->send();
            });
    }

    /**
     * Remove a colleague. A soft delete through the action, so who removed whom
     * — and why — survives in the audit trail. The Owner can never be removed
     * (ADR-029).
     *
     * KEPT alongside deactivation: they are different endings. This one is
     * final and frees the person from the roster; the other is a pause.
     */
    private static function removeAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('remove')
            ->label(__('organization.team.action.remove'))
            ->icon('heroicon-o-user-minus')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('organization.team.action.remove_confirm'))
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label(__('organization.team.reason'))
                    ->helperText(__('organization.team.reason_help'))
                    ->maxLength(500),
            ])
            ->visible(fn (OrganizationMember $record): bool => ! $record->isOwner()
                && auth()->user()?->can('remove', $record) === true)
            ->action(function (OrganizationMember $record, array $data): void {
                app(RemoveMemberAction::class)->run($record, static::reason($data));

                Notification::make()->title(__('organization.team.removed'))->success()->send();
            });
    }

    /**
     * The companies the actor may exercise a capability in, id => name.
     *
     * @return array<int, string>
     */
    private static function organizationOptions(OrganizationCapability $capability): array
    {
        $userId = (int) auth()->id();
        $members = app(OrganizationMemberRepositoryContract::class);

        $ids = array_values(array_filter(
            $members->organizationIdsForUser($userId),
            static fn (int $id): bool => $members->findMembership($id, $userId)?->can($capability) === true,
        ));

        if ($ids === []) {
            return [];
        }

        /** @var array<int, string> $options */
        $options = Organization::query()
            ->whereIn('id', $ids)
            ->orderBy('legal_name')
            ->pluck('legal_name', 'id')
            ->all();

        return $options;
    }

    /**
     * Resolve an organization THROUGH the membership scope, so a forged id in
     * the modal payload cannot reach a company the actor does not belong to.
     */
    private static function scopedOrganization(int $id): Organization
    {
        $ids = app(OrganizationMemberRepositoryContract::class)
            ->organizationIdsForUser((int) auth()->id());

        return Organization::query()->whereIn('id', $ids)->findOrFail($id);
    }

    /**
     * Assignable ORG roles, labelled. Owner is absent by construction —
     * `assignable()` excludes it (ADR-029).
     *
     * @return array<string, string>
     */
    private static function assignableRoleOptions(): array
    {
        $options = [];

        foreach (OrganizationRole::assignable() as $role) {
            $options[$role->value] = $role->label();
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function reason(array $data): ?string
    {
        $reason = $data['reason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }
}
