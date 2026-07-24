<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Seller\Resources;

use App\Modules\Organization\Domain\Enums\OrganizationStatus;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource\Pages;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The seller-panel view of the organizations the operator belongs to.
 *
 * READ-ONLY and MEMBERSHIP-SCOPED (ADR-030): the query is confined to the
 * current user's active memberships, so one seller never sees another's company
 * in the panel. Rich management (members, KYC, requests) is driven through the
 * Next.js dashboard on the API; this panel is a read surface.
 */
final class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $recordTitleAttribute = 'legal_name';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('legal_name')->label(__('organization.legal_name'))->searchable(),
                Tables\Columns\TextColumn::make('slug')->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (OrganizationStatus $state): string => $state->value),
                Tables\Columns\TextColumn::make('plan.name')->label(__('organization.plan'))->toggleable(),
            ])
            ->actions([Tables\Actions\ViewAction::make()])
            ->bulkActions([]);
    }

    /**
     * Confined to the acting user's active memberships — the tenancy wall.
     *
     * @return Builder<Organization>
     */
    public static function getEloquentQuery(): Builder
    {
        $ids = app(\App\Modules\Organization\Domain\Contracts\OrganizationMemberRepositoryContract::class)
            ->organizationIdsForUser((int) auth()->id());

        return parent::getEloquentQuery()->with('plan')->whereIn('id', $ids);
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
