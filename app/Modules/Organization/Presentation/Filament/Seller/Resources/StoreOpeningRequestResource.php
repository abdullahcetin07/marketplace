<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Seller\Resources;

use App\Modules\Organization\Application\Actions\CancelStoreOpeningRequestAction;
use App\Modules\Organization\Application\Actions\SubmitStoreOpeningRequestAction;
use App\Modules\Organization\Domain\Contracts\OrganizationMemberRepositoryContract;
use App\Modules\Organization\Domain\Enums\StoreOpeningRequestStatus;
use App\Modules\Organization\Domain\Exceptions\StoreOpeningException;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\StoreOpeningRequest;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\StoreOpeningRequestResource\Pages;
use App\Modules\Organization\Presentation\Filament\Seller\Support\StoreProposal;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The seller's requests to open a store (ADR-028) — the core of onboarding.
 *
 * NO STORE IS CREATED HERE, and that is the whole design. A seller drafts a
 * request, submits it into the admin queue, and an admin's approval is what
 * fires `StoreOpeningApproved`; the Store module then creates the Store, which
 * appears in the seller's StoreResource. This resource must never shortcut that
 * chain.
 *
 * WHY A SEPARATE CLASS from the admin `StoreOpeningRequestResource`: that one is
 * the approval queue — it holds approve/reject and reads across every
 * organization. Sharing it with the seller panel would put a cross-org admin
 * surface one registration mistake away from a seller. The two have opposite
 * audiences, so they are two resources over one model.
 *
 * MEMBERSHIP-SCOPED (ADR-030) exactly as OrganizationResource is: the query is
 * confined to the actor's organizations, and each row action re-checks the
 * policy.
 *
 * @see App\Modules\Organization\Presentation\Controllers\Api\StoreRequestController
 * @see App\Modules\Organization\Presentation\Filament\Resources\StoreOpeningRequestResource
 */
final class StoreOpeningRequestResource extends Resource
{
    protected static ?string $model = StoreOpeningRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'store_name';

    public static function getNavigationGroup(): ?string
    {
        return __('nav.store');
    }

    public static function getModelLabel(): string
    {
        return __('organization.store_request.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('organization.store_request.plural');
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | The panel is seller-guarded and getEloquentQuery() is the tenancy wall, so
    | the listing is open to any panel user; every row action answers to
    | StoreOpeningRequestPolicy. Creating is gated per-organization inside the
    | form, because the capability lives on the membership and a seller may
    | belong to several companies.
    |
    | There is no edit and no delete: no update action exists in the module, and
    | withdrawing a request is `cancel` — a recorded state change, not a
    | deletion.
    */

    public static function canViewAny(): bool
    {
        return true;
    }

    /**
     * THE FORM SURVIVES; ITS ENTRY POINT MOVED (owner-approved reflow).
     *
     * A seller's FIRST store is requested with their company, on the onboarding
     * form. An ADDITIONAL one is requested from "Mağazalarım", where a seller
     * looking at their stores is already thinking about stores — that page
     * links here. What this resource no longer advertises is a "new request"
     * button of its own: it became what it always mostly was, the STATUS view
     * (pending / approved / rejected) plus submit and withdraw.
     *
     * The page is kept rather than duplicated into Store's panel because Store
     * may not import Organization (ADR-033). The link is a route name; the form
     * stays owned by the module that owns the request.
     */
    public static function canCreate(): bool
    {
        return self::organizationsThatMayRequest() !== [];
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

    /**
     * The proposal. Mirrors `CreateStoreOpeningRequestRequest`, minus the
     * category: the Catalog module does not exist yet (there is no categories
     * table to choose from), so offering the field would mean inventing an
     * identifier. The API keeps accepting it for when Catalog lands.
     */
    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\Select::make('organization_id')
                ->label(__('organization.singular'))
                ->options(fn (): array => self::organizationsThatMayRequest())
                ->required()
                ->native(false)
                // A seller may belong to several companies but hold the
                // capability in only some of them (ADR-030).
                ->helperText(__('organization.store_request.organization_hint')),

            Forms\Components\TextInput::make('store_name')
                ->label(__('organization.store_request.name'))
                ->required()
                ->maxLength(255)
                ->helperText(__('organization.store_request.name_hint'))
                // The same rule the onboarding form applies — one definition,
                // so the two entry points cannot disagree about a taken name.
                ->rule(static fn (): \Closure => StoreProposal::uniqueNameRule()),

            Forms\Components\TextInput::make('slug')
                ->label(__('organization.store_request.slug'))
                ->helperText(__('organization.store_request.slug_hint'))
                ->required()
                ->maxLength(255)
                ->rule('alpha_dash'),

            Forms\Components\Textarea::make('description')
                ->label(__('organization.store_request.description'))
                ->maxLength(2000)
                ->rows(3),

            Forms\Components\Textarea::make('reason')
                ->label(__('organization.store_request.reason'))
                ->helperText(__('organization.store_request.reason_hint'))
                ->maxLength(1000)
                ->rows(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('store_name')
                    ->label(__('organization.store_request.name'))
                    ->searchable(),

                Tables\Columns\TextColumn::make('organization.legal_name')
                    ->label(__('organization.singular'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('organization.status'))
                    ->badge()
                    ->color(fn (StoreOpeningRequestStatus $state): string => match ($state) {
                        StoreOpeningRequestStatus::Approved => 'success',
                        StoreOpeningRequestStatus::Pending => 'warning',
                        StoreOpeningRequestStatus::Draft => 'gray',
                        StoreOpeningRequestStatus::Rejected => 'danger',
                        StoreOpeningRequestStatus::Cancelled => 'gray',
                    })
                    ->formatStateUsing(fn (StoreOpeningRequestStatus $state): string => __("enums.StoreOpeningRequestStatus.{$state->value}")),

                Tables\Columns\TextColumn::make('submitted_at')
                    ->label(__('organization.store_request.submitted_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),

                // The admin's explanation when a request is turned down.
                Tables\Columns\TextColumn::make('review_notes')
                    ->label(__('organization.store_request.review_notes'))
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(
                    collect(StoreOpeningRequestStatus::cases())
                        ->mapWithKeys(fn (StoreOpeningRequestStatus $s): array => [
                            $s->value => __("enums.StoreOpeningRequestStatus.{$s->value}"),
                        ])
                        ->all(),
                ),
            ])
            ->actions([
                self::submitAction(),
                self::cancelAction(),
            ])
            ->bulkActions([])
            ->emptyStateIcon('heroicon-o-building-storefront')
            ->emptyStateHeading(__('organization.store_request.empty.heading'))
            ->emptyStateDescription(__('organization.store_request.empty.description'))
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Draft → pending review. The action performs the fail-fast store-limit and
     * operational-status checks and refuses with a domain exception; those are
     * EXPECTED refusals ("your plan is used up", "your company is not approved
     * yet"), so they surface as a warning notification rather than an error
     * page.
     */
    private static function submitAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('submit')
            ->label(__('organization.store_request.action.submit'))
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->requiresConfirmation()
            ->modalDescription(__('organization.store_request.action.submit_confirm'))
            ->visible(fn (StoreOpeningRequest $record): bool => $record->status === StoreOpeningRequestStatus::Draft
                && auth()->user()?->can('submit', $record) === true)
            ->action(function (StoreOpeningRequest $record): void {
                try {
                    app(SubmitStoreOpeningRequestAction::class)->run($record);
                } catch (StoreOpeningException $exception) {
                    Notification::make()
                        ->title(__('organization.store_request.submit_failed'))
                        ->body($exception->getMessage())
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()->title(__('organization.store_request.submitted'))->success()->send();
            });
    }

    /**
     * Withdraw before a decision. Only draft and pending are cancellable — the
     * enum owns that rule and the action enforces it again.
     */
    private static function cancelAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('cancel')
            ->label(__('organization.store_request.action.cancel'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (StoreOpeningRequest $record): bool => $record->status->isCancellable()
                && auth()->user()?->can('cancel', $record) === true)
            ->action(function (StoreOpeningRequest $record): void {
                try {
                    app(CancelStoreOpeningRequestAction::class)->run($record);
                } catch (StoreOpeningException $exception) {
                    Notification::make()
                        ->title(__('organization.store_request.cancel_failed'))
                        ->body($exception->getMessage())
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()->title(__('organization.store_request.cancelled'))->success()->send();
            });
    }

    /**
     * The organizations the actor may raise a request for, as select options.
     *
     * Membership alone is not enough — `createStoreRequest` is a capability, so
     * a Viewer in a company sees that company's requests but cannot start one.
     *
     * @return array<int, string>
     */
    private static function organizationsThatMayRequest(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        return Organization::query()
            ->whereIn('id', self::memberOrganizationIds())
            ->get()
            ->filter(fn (Organization $organization): bool => $user->can('createStoreRequest', $organization))
            ->pluck('legal_name', 'id')
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private static function memberOrganizationIds(): array
    {
        return app(OrganizationMemberRepositoryContract::class)
            ->organizationIdsForUser((int) auth()->id());
    }

    /**
     * The tenancy wall (ADR-030): only requests belonging to an organization
     * the actor is an active member of.
     *
     * @return Builder<StoreOpeningRequest>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('organization')
            ->whereIn('organization_id', self::memberOrganizationIds());
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStoreOpeningRequests::route('/'),
            // Reachable, not advertised — "Mağazalarım" links here.
            'create' => Pages\CreateStoreOpeningRequest::route('/create'),
        ];
    }
}
