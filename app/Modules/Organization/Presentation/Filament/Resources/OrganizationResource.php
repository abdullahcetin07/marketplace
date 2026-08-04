<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Resources;

use App\Core\Domain\Context\AuditContext;
use App\Modules\Organization\Application\Actions\ApproveOrganizationAction;
use App\Modules\Organization\Application\Actions\RejectOrganizationAction;
use App\Modules\Organization\Application\Actions\RestoreOrganizationAction;
use App\Modules\Organization\Application\Actions\SuspendOrganizationAction;
use App\Modules\Organization\Domain\Enums\OrganizationDocumentStatus;
use App\Modules\Organization\Domain\Enums\OrganizationStatus;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Presentation\Filament\Resources\OrganizationResource\Pages;
use App\Modules\Organization\Presentation\Filament\Resources\OrganizationResource\RelationManagers\DocumentsRelationManager;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
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
 * THE REVIEW SURFACE. The View page's infolist is what an approval is actually
 * decided on: the company, its KYC and its payout account. Both encrypted fields
 * — the authorised person's national id and the IBAN — are shown MASKED. A
 * reviewer needs to confirm that a number was supplied and matches the tail on a
 * document; they never need the full value, and rendering it would put a
 * credential and a personal identifier into a browser, a screenshot and a
 * support ticket. Masking here is what keeps `encrypted`-at-rest and
 * audit-exclusion from being undone by the UI that reads the record.
 *
 * @see App\Modules\Organization\Presentation\Controllers\Api\Admin\OrganizationController
 * @see OrganizationResource\RelationManagers\DocumentsRelationManager
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

    /**
     * Everything the approve/reject decision rests on, read-only.
     *
     * Nothing here is editable: an admin verifies what the seller submitted, and
     * corrections are the seller's to make. The encrypted fields are masked —
     * see the class docblock.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('organization.review.company'))
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('legal_name')->label(__('organization.legal_name')),
                    Infolists\Components\TextEntry::make('display_name')->label(__('organization.display_name'))
                        ->placeholder(__('organization.review.not_provided')),
                    Infolists\Components\TextEntry::make('slug')->label(__('organization.slug')),

                    Infolists\Components\TextEntry::make('status')
                        ->label(__('organization.status'))
                        ->badge()
                        ->color(fn (OrganizationStatus $state): string => self::statusColor($state))
                        ->formatStateUsing(fn (OrganizationStatus $state): string => $state->value),
                    Infolists\Components\TextEntry::make('country.name')->label(__('organization.country'))
                        ->placeholder(__('organization.review.not_provided')),
                    Infolists\Components\TextEntry::make('currency.code')->label(__('organization.currency'))
                        ->placeholder(__('organization.review.not_provided')),

                    Infolists\Components\TextEntry::make('owner.name')->label(__('organization.review.owner')),
                    Infolists\Components\TextEntry::make('owner.email')->label(__('organization.review.owner_email'))
                        ->copyable(),
                    Infolists\Components\TextEntry::make('created_at')->label(__('organization.review.registered_at'))
                        ->dateTime(),
                ]),

            Infolists\Components\Section::make(__('organization.review.kyc'))
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('kyc.submitted_at')
                        ->label(__('organization.kyc.submitted_at'))
                        ->dateTime()
                        ->placeholder(__('organization.kyc.not_submitted')),
                    Infolists\Components\TextEntry::make('kyc.tax_number')
                        ->label(__('organization.kyc.tax_number'))
                        ->placeholder(__('organization.review.not_provided')),
                    Infolists\Components\TextEntry::make('kyc.registration_number')
                        ->label(__('organization.kyc.registration_number'))
                        ->placeholder(__('organization.review.not_provided')),

                    Infolists\Components\TextEntry::make('kyc.authorized_person_name')
                        ->label(__('organization.kyc.authorized_person_name'))
                        ->placeholder(__('organization.review.not_provided')),

                    /*
                    | The national id is encrypted at rest and excluded from the
                    | audit trail. The last four digits are enough to check it
                    | against the identity document in the next section; the full
                    | value never reaches the browser.
                    */
                    Infolists\Components\TextEntry::make('masked_national_id')
                        ->label(__('organization.kyc.national_id'))
                        ->state(fn (Organization $record): ?string => self::maskTail(
                            $record->kyc?->authorized_person_national_id,
                        ))
                        ->helperText(__('organization.review.masked_hint'))
                        ->placeholder(__('organization.review.not_provided')),

                    Infolists\Components\KeyValueEntry::make('kyc.metadata')
                        ->label(__('organization.kyc.metadata'))
                        ->columnSpanFull(),
                ]),

            Infolists\Components\Section::make(__('organization.review.bank'))
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('bankAccount.account_holder')
                        ->label(__('organization.bank.account_holder'))
                        ->placeholder(__('organization.bank.not_set')),
                    Infolists\Components\TextEntry::make('bankAccount.bank_name')
                        ->label(__('organization.bank.bank_name'))
                        ->placeholder(__('organization.review.not_provided')),

                    // The model owns the mask; a payout credential has exactly
                    // one presentable form.
                    Infolists\Components\TextEntry::make('masked_iban')
                        ->label(__('organization.bank.iban'))
                        ->state(fn (Organization $record): ?string => $record->bankAccount?->maskedIban())
                        ->helperText(__('organization.review.masked_hint'))
                        ->placeholder(__('organization.bank.not_set')),

                    Infolists\Components\TextEntry::make('bankAccount.currency.code')
                        ->label(__('organization.bank.currency'))
                        ->placeholder(__('organization.review.not_provided')),
                    Infolists\Components\TextEntry::make('bankAccount.verified_at')
                        ->label(__('organization.review.verified_at'))
                        ->dateTime()
                        ->placeholder(__('organization.review.not_provided')),
                ]),
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
                    ->color(fn (OrganizationStatus $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (OrganizationStatus $state): string => $state->value),

                /*
                | The soft nudge, at queue level: a company with unreviewed
                | documents is visible as such before anyone opens it. It does
                | not block approval — the domain has no such rule and this
                | surface does not invent one (ADR-018).
                */
                Tables\Columns\TextColumn::make('pending_documents_count')
                    ->label(__('organization.review.pending_documents'))
                    ->badge()
                    ->color(fn (mixed $state): string => ((int) $state) > 0 ? 'warning' : 'gray'),

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
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            DocumentsRelationManager::class,
        ];
    }

    /**
     * @return Builder<Organization>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            // The review infolist reads the owner, the KYC row and the payout
            // account; strict mode turns a missed eager load into an exception.
            ->with(['plan', 'country', 'currency', 'owner', 'kyc', 'bankAccount.currency'])
            ->withCount([
                'documents as pending_documents_count' => static fn (Builder $query) => $query
                    ->where('status', OrganizationDocumentStatus::Pending->value),
            ]);
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

    /**
     * A lifecycle decision as a row action: policy-gated, reason-carrying,
     * delegating to the module action.
     *
     * @param class-string $actionClass
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
            /*
            | A soft nudge, not a gate: approving a company whose documents
            | nobody has read is almost always a mistake, but whether it is
            | allowed is the domain's call, and the domain permits it. So the
            | modal says so and lets the admin proceed.
            */
            ->modalDescription(function (Organization $record) use ($name): ?string {
                if ($name !== 'approve') {
                    return null;
                }

                $pending = self::pendingDocumentCount($record);

                return $pending > 0
                    ? __('organization.review.pending_documents_warning', ['count' => $pending])
                    : null;
            })
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

    private static function statusColor(OrganizationStatus $status): string
    {
        return match ($status) {
            OrganizationStatus::Approved => 'success',
            OrganizationStatus::Pending => 'warning',
            OrganizationStatus::Rejected, OrganizationStatus::Suspended => 'danger',
            OrganizationStatus::Archived => 'gray',
        };
    }

    /**
     * The last four characters of an encrypted value, or null when there is
     * nothing on file. Anything short enough to be guessable from its tail is
     * masked entirely rather than half-revealed.
     */
    private static function maskTail(?string $value, int $visible = 4): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (mb_strlen($value) <= $visible) {
            return str_repeat('•', mb_strlen($value));
        }

        return '•••• '.mb_substr($value, -$visible);
    }

    /**
     * How many documents are still awaiting review.
     *
     * Read from the list query's `withCount` when it is there, and counted
     * directly when it is not — the modal is also reachable from surfaces that
     * did not select the aggregate, and strict mode turns a missing attribute
     * into an exception rather than a zero.
     */
    private static function pendingDocumentCount(Organization $record): int
    {
        $counted = $record->getAttributes()['pending_documents_count'] ?? null;

        if ($counted !== null) {
            return (int) $counted;
        }

        return $record->documents()
            ->where('status', OrganizationDocumentStatus::Pending->value)
            ->count();
    }
}
