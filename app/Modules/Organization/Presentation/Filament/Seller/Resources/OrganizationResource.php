<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Seller\Resources;

use App\Modules\Localization\Domain\Models\Country;
use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Organization\Domain\Contracts\OrganizationMemberRepositoryContract;
use App\Modules\Organization\Domain\Enums\OrganizationStatus;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationPlan;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource\Pages;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource\RelationManagers;
use App\Shared\Enums\UserType;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * The seller's own companies — the first stop of self-service onboarding.
 *
 * MEMBERSHIP-SCOPED (ADR-030): the query is confined to the current user's
 * active memberships, so one seller never sees another's company. Every write
 * delegates to a module Action through its page (Register/Update) or a modal
 * action (KYC, bank account) — this class owns no business rule, exactly like
 * the seller API controllers it mirrors.
 *
 * WHY canViewAny() IS OVERRIDDEN: `organization.*` are ADMIN Spatie permissions
 * (OrganizationServiceProvider registers the resource for UserType::Admin only),
 * so `OrganizationPolicy::viewAny` denies every seller. The panel is
 * seller-guarded and the query above is the tenancy wall, so the listing is open
 * to any panel user and per-record authorization stays in the policy — the same
 * reasoning as the seller StoreResource.
 *
 * @see App\Modules\Organization\Presentation\Controllers\Api\OrganizationController
 * @see App\Modules\Store\Presentation\Filament\Seller\Resources\StoreResource
 */
final class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $recordTitleAttribute = 'legal_name';

    public static function getModelLabel(): string
    {
        return __('organization.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('organization.plural');
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | Each gate mirrors the seller API's FormRequest for the same operation, so
    | the two surfaces can never drift apart:
    |   create → RegisterOrganizationRequest  (any authenticated seller)
    |   edit   → UpdateOrganizationRequest    (the OrganizationUpdate capability)
    | Deleting a company is not a seller operation at all — an organization is
    | suspended or archived by an admin, never removed from the panel.
    */

    public static function canViewAny(): bool
    {
        return true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->type === UserType::Seller;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update', $record) === true;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * Registration fields on create; profile fields on edit.
     *
     * The create schema is `RegisterOrganizationRequest` field-for-field —
     * locale arrives as ISO CODES, never internal ids (the action resolves
     * them). `UpdateOrganizationAction` writes only the two name columns, so
     * everything else is create-only: slug, country, currency and plan are not
     * free-form profile edits.
     */
    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('legal_name')
                ->label(__('organization.legal_name'))
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('display_name')
                ->label(__('organization.display_name'))
                ->helperText(__('organization.display_name_hint'))
                ->maxLength(255),

            Forms\Components\TextInput::make('slug')
                ->label(__('organization.slug'))
                ->helperText(__('organization.slug_hint'))
                ->required()
                ->maxLength(255)
                ->rule('alpha_dash')
                ->unique('organizations', 'slug')
                ->visibleOn('create'),

            Forms\Components\Select::make('country_code')
                ->label(__('organization.country'))
                ->options(fn (): array => Country::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'iso2')
                    ->all())
                ->searchable()
                ->required()
                ->rule(Rule::exists('countries', 'iso2')->where('is_active', true))
                ->visibleOn('create'),

            Forms\Components\Select::make('currency_code')
                ->label(__('organization.currency'))
                ->options(fn (): array => Currency::query()
                    ->where('is_active', true)
                    ->orderBy('code')
                    ->pluck('name', 'code')
                    ->all())
                ->searchable()
                ->required()
                ->rule(Rule::exists('currencies', 'code')->where('is_active', true))
                ->visibleOn('create'),

            Forms\Components\Select::make('plan_slug')
                ->label(__('organization.plan'))
                ->options(fn (): array => OrganizationPlan::query()
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->pluck('name', 'slug')
                    ->all())
                ->rule(Rule::exists('organization_plans', 'slug')->where('is_active', true))
                ->visibleOn('create'),
        ]);
    }

    /**
     * The read surface. KYC and the payout account are summarised, never
     * exposed: the national id is not shown at all and the IBAN only as its
     * masked last four — both are encrypted and audit-excluded for a reason.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('organization.singular'))->schema([
                Infolists\Components\TextEntry::make('legal_name')->label(__('organization.legal_name')),
                Infolists\Components\TextEntry::make('display_name')->label(__('organization.display_name'))->placeholder('—'),
                Infolists\Components\TextEntry::make('slug')->label(__('organization.slug')),
                Infolists\Components\TextEntry::make('status')
                    ->label(__('organization.status'))
                    ->badge()
                    ->formatStateUsing(fn (OrganizationStatus $state): string => $state->label()),
                Infolists\Components\TextEntry::make('country.name')->label(__('organization.country')),
                Infolists\Components\TextEntry::make('currency.code')->label(__('organization.currency')),
                Infolists\Components\TextEntry::make('plan.name')->label(__('organization.plan'))->placeholder('—'),
            ])->columns(2),

            Infolists\Components\Section::make(__('organization.kyc.title'))->schema([
                Infolists\Components\TextEntry::make('kyc.submitted_at')
                    ->label(__('organization.kyc.submitted_at'))
                    ->dateTime()
                    ->placeholder(__('organization.kyc.not_submitted')),
                Infolists\Components\TextEntry::make('kyc.tax_number')->label(__('organization.kyc.tax_number'))->placeholder('—'),
                Infolists\Components\TextEntry::make('kyc.registration_number')->label(__('organization.kyc.registration_number'))->placeholder('—'),
                Infolists\Components\TextEntry::make('kyc.authorized_person_name')->label(__('organization.kyc.authorized_person_name'))->placeholder('—'),
            ])->columns(2),

            Infolists\Components\Section::make(__('organization.bank.title'))->schema([
                Infolists\Components\TextEntry::make('bankAccount.account_holder')
                    ->label(__('organization.bank.account_holder'))
                    ->placeholder(__('organization.bank.not_set')),
                Infolists\Components\TextEntry::make('bankAccount.bank_name')->label(__('organization.bank.bank_name'))->placeholder('—'),
                // Never the full number — the masked form is the only one a UI
                // ever renders.
                Infolists\Components\TextEntry::make('masked_iban')
                    ->label(__('organization.bank.iban'))
                    ->state(fn (Organization $record): ?string => $record->bankAccount?->maskedIban())
                    ->placeholder('—'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('legal_name')->label(__('organization.legal_name'))->searchable(),
                Tables\Columns\TextColumn::make('slug')->label(__('organization.slug'))->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('organization.status'))
                    ->badge()
                    ->color(fn (OrganizationStatus $state): string => match ($state) {
                        OrganizationStatus::Approved => 'success',
                        OrganizationStatus::Pending => 'warning',
                        OrganizationStatus::Rejected, OrganizationStatus::Suspended => 'danger',
                        OrganizationStatus::Archived => 'gray',
                    })
                    ->formatStateUsing(fn (OrganizationStatus $state): string => $state->label()),
                Tables\Columns\TextColumn::make('plan.name')->label(__('organization.plan'))->toggleable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([])

            /*
            | A seller who has just registered lands here with nothing. The
            | empty state is the onboarding call to action, not a shrug.
            */
            ->emptyStateIcon('heroicon-o-building-office-2')
            ->emptyStateHeading(__('organization.empty.heading'))
            ->emptyStateDescription(__('organization.empty.description'))
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()->label(__('organization.action.create')),
            ]);
    }

    /**
     * Confined to the acting user's active memberships — the tenancy wall.
     *
     * @return Builder<Organization>
     */
    public static function getEloquentQuery(): Builder
    {
        $ids = app(OrganizationMemberRepositoryContract::class)
            ->organizationIdsForUser((int) auth()->id());

        return parent::getEloquentQuery()
            ->with(['plan', 'country', 'currency', 'kyc', 'bankAccount'])
            ->whereIn('id', $ids);
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            RelationManagers\DocumentsRelationManager::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrganizations::route('/'),
            'create' => Pages\CreateOrganization::route('/create'),
            'view' => Pages\ViewOrganization::route('/{record}'),
            'edit' => Pages\EditOrganization::route('/{record}/edit'),
        ];
    }
}
