<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Resources;

use App\Core\Domain\Context\AuditContext;
use App\Modules\Organization\Application\Actions\ApproveOrganizationAction;
use App\Modules\Organization\Application\Actions\RejectOrganizationAction;
use App\Modules\Organization\Application\Actions\RestoreOrganizationAction;
use App\Modules\Organization\Application\Actions\SuspendOrganizationAction;
use App\Modules\Organization\Domain\Enums\OrganizationStatus;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Presentation\Filament\Resources\OrganizationResource\Pages;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The admin KYC / lifecycle queue for organizations — the UI twin of the admin
 * API.
 *
 * STRICTLY PRESENTATION. Every decision delegates to the module Actions
 * (approve/reject/suspend/reinstate), wrapped in the audit context so the reason
 * is recorded; every gate is the same OrganizationPolicy. Nothing here owns a
 * rule.
 *
 * @see App\Modules\Organization\Presentation\Controllers\Api\Admin\OrganizationController
 */
final class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'legal_name';

    public static function getNavigationGroup(): ?string
    {
        return __('nav.sellers');
    }

    public static function form(Forms\Form $form): Forms\Form
    {
        // Read-only detail; lifecycle changes happen through the row actions,
        // never a free-form edit.
        return $form->schema([
            Forms\Components\TextInput::make('legal_name')->disabled(),
            Forms\Components\TextInput::make('slug')->disabled(),
            Forms\Components\TextInput::make('status')->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('legal_name')->label(__('organization.legal_name'))->searchable(),
                Tables\Columns\TextColumn::make('slug')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (OrganizationStatus $state): string => match ($state) {
                        OrganizationStatus::Approved => 'success',
                        OrganizationStatus::Pending => 'warning',
                        OrganizationStatus::Rejected, OrganizationStatus::Suspended => 'danger',
                        OrganizationStatus::Archived => 'gray',
                    })
                    ->formatStateUsing(fn (OrganizationStatus $state): string => $state->value),
                Tables\Columns\TextColumn::make('plan.name')->label(__('organization.plan'))->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(
                    collect(OrganizationStatus::cases())->mapWithKeys(fn (OrganizationStatus $s): array => [$s->value => $s->value])->all(),
                ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                self::decisionAction('approve', 'heroicon-o-check-circle', 'success', ApproveOrganizationAction::class, OrganizationStatus::Pending),
                self::decisionAction('reject', 'heroicon-o-x-circle', 'danger', RejectOrganizationAction::class, OrganizationStatus::Pending, reasonRequired: true),
                self::decisionAction('suspend', 'heroicon-o-pause-circle', 'warning', SuspendOrganizationAction::class, OrganizationStatus::Approved, reasonRequired: true),
                self::decisionAction('reinstate', 'heroicon-o-play-circle', 'success', RestoreOrganizationAction::class, OrganizationStatus::Suspended),
            ])
            ->bulkActions([]);
    }

    /**
     * A lifecycle decision as a row action: policy-gated, reason-carrying,
     * delegating to the module action.
     *
     * @param  class-string  $actionClass
     */
    private static function decisionAction(
        string $name,
        string $icon,
        string $color,
        string $actionClass,
        OrganizationStatus $visibleWhen,
        bool $reasonRequired = false,
    ): Tables\Actions\Action {
        return Tables\Actions\Action::make($name)
            ->label(__("organization.action.{$name}"))
            ->icon($icon)
            ->color($color)
            ->requiresConfirmation()
            ->form([
                Forms\Components\Textarea::make('reason')->label(__('organization.action.reason'))->required($reasonRequired)->maxLength(1000),
            ])
            ->visible(fn (Organization $record): bool => $record->status === $visibleWhen
                && auth()->user()?->can($name, $record) === true)
            ->action(function (Organization $record, array $data) use ($actionClass): void {
                AuditContext::withReasonFor(
                    $data['reason'] ?? null,
                    fn () => app($actionClass)->run($record, auth()->user(), $data['reason'] ?? null),
                );

                Notification::make()->title(__("organization.{$record->status->value}"))->success()->send();
            });
    }

    /**
     * @return Builder<Organization>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['plan', 'country', 'currency']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrganizations::route('/'),
            'view' => Pages\ViewOrganization::route('/{record}'),
        ];
    }
}
