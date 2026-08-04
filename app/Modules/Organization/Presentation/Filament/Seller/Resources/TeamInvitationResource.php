<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Seller\Resources;

use App\Modules\Organization\Application\Actions\CancelInvitationAction;
use App\Modules\Organization\Application\Actions\ResendInvitationAction;
use App\Modules\Organization\Domain\Contracts\OrganizationMemberRepositoryContract;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\OrganizationInvitation;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\TeamInvitationResource\Pages;
use App\Shared\Enums\InvitationStatus;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Davetler — the pending half of the team.
 *
 * A membership only exists once the invited person accepts (ADR-031), so an
 * invitation in flight is invisible on the members list. Without this surface a
 * seller who invited the wrong address had no way to see it, let alone withdraw
 * it — and would keep re-inviting, issuing a new live token each time.
 *
 * MEMBERSHIP-SCOPED, exactly as the members list: confined to the acting user's
 * own organizations, then re-checked per row by
 * `OrganizationInvitationPolicy`, which requires the `invitation.manage`
 * capability IN THAT organization.
 *
 * THE TOKEN IS NEVER SHOWN. Only its hash is stored (ADR-025/031) and the raw
 * value goes to the invited mailbox alone — there is no "copy link" here, by
 * design. Re-sending issues a NEW token and kills the old one, which is why it
 * is offered instead.
 *
 * @see App\Modules\Organization\Presentation\Controllers\Api\MemberController
 * @see App\Modules\Organization\Presentation\Filament\Seller\Resources\TeamMemberResource
 */
final class TeamInvitationResource extends Resource
{
    protected static ?string $model = OrganizationInvitation::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope-open';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'email';

    public static function getNavigationGroup(): string
    {
        return __('nav.team');
    }

    public static function getModelLabel(): string
    {
        return __('organization.team.invitation_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('organization.team.invitation_plural');
    }

    /**
     * @see TeamMemberResource for why this is open and the query is the wall.
     */
    public static function canViewAny(): bool
    {
        return true;
    }

    /**
     * Issuing an invitation is the members list's action — it needs an
     * organization and a role, and it belongs beside the team it adds to.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * An invitation is CANCELLED, not deleted: the terminal row is the evidence
     * that the address was invited and by whom.
     */
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
                Tables\Columns\TextColumn::make('email')
                    ->label(__('organization.team.member_email'))
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('organization.legal_name')
                    ->label(__('organization.singular'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('role')
                    ->label(__('organization.team.role'))
                    ->badge()
                    ->formatStateUsing(fn (OrganizationRole $state): string => $state->label()),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('organization.team.status'))
                    ->badge()
                    ->color(fn (InvitationStatus $state): string => match ($state) {
                        InvitationStatus::Accepted => 'success',
                        InvitationStatus::Pending => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (InvitationStatus $state): string => $state->label()),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label(__('organization.team.expires_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('organization.team.invited_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('organization.team.status'))
                    ->options(fn (): array => InvitationStatus::options()),
            ])
            ->actions([
                self::resendAction(),
                self::cancelAction(),
            ])
            ->bulkActions([])
            ->emptyStateIcon('heroicon-o-envelope-open')
            ->emptyStateHeading(__('organization.team.invitation_empty.heading'))
            ->emptyStateDescription(__('organization.team.invitation_empty.description'))
            ->defaultSort('id', 'desc');
    }

    /**
     * The tenancy wall (ADR-030) — the acting user's own organizations only.
     *
     * @return Builder<OrganizationInvitation>
     */
    public static function getEloquentQuery(): Builder
    {
        $ids = app(OrganizationMemberRepositoryContract::class)
            ->organizationIdsForUser((int) auth()->id());

        /** @var Builder<OrganizationInvitation> $query */
        $query = parent::getEloquentQuery();

        return $query
            ->with('organization')
            ->whereIn('organization_id', $ids);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTeamInvitations::route('/'),
        ];
    }

    /**
     * Re-send with a fresh token. The previous link stops working the moment
     * this runs — the stored hash is replaced — which is the point: one live
     * token per invitation, exactly like a re-issued password reset.
     */
    private static function resendAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('resend')
            ->label(__('organization.team.action.resend'))
            ->icon('heroicon-o-paper-airplane')
            ->requiresConfirmation()
            ->modalDescription(__('organization.team.action.resend_confirm'))
            ->visible(fn (OrganizationInvitation $record): bool => $record->isPending()
                && auth()->user()?->can('resend', $record) === true)
            ->action(function (OrganizationInvitation $record): void {
                app(ResendInvitationAction::class)->run($record);

                Notification::make()->title(__('organization.invitation_sent'))->success()->send();
            });
    }

    private static function cancelAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('cancel')
            ->label(__('organization.team.action.cancel'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('organization.team.action.cancel_confirm'))
            ->visible(fn (OrganizationInvitation $record): bool => $record->isPending()
                && auth()->user()?->can('cancel', $record) === true)
            ->action(function (OrganizationInvitation $record): void {
                app(CancelInvitationAction::class)->run($record);

                Notification::make()->title(__('organization.team.invitation_cancelled'))->success()->send();
            });
    }
}
