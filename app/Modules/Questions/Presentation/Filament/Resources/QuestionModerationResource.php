<?php

declare(strict_types=1);

namespace App\Modules\Questions\Presentation\Filament\Resources;

use App\Core\Domain\Contracts\CatalogBrowseContract;
use App\Modules\Questions\Application\Actions\HideQuestionAction;
use App\Modules\Questions\Application\Actions\UnhideQuestionAction;
use App\Modules\Questions\Domain\DTOs\HideQuestionDTO;
use App\Modules\Questions\Domain\Enums\QuestionStatus;
use App\Modules\Questions\Domain\Models\Question;
use App\Modules\Questions\Presentation\Filament\Resources\QuestionModerationResource\Pages;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The platform's only lever over a Q&A: hide, and un-hide (ADR-071).
 *
 * **THERE IS NO ANSWER ACTION HERE, AND THAT IS THE POINT OF THE CLASS.** An
 * admin looking at an unanswered question can see it, judge it and take it
 * down — and cannot reply. The platform speaking in a merchant's place is a
 * promise the merchant did not make, so the ability does not exist rather than
 * being denied somewhere.
 *
 * **REACTIVE, NOT PRE-MODERATION** — the mirror of Reviews' queue (ADR-070). A
 * review waits for staff before anybody sees it; a question waits for the
 * MERCHANT, and staff arrive afterwards only when something is wrong. So this
 * screen defaults to everything rather than to a "pending" filter: there is no
 * backlog here that blocks anything, and an empty default would hide the answered
 * pairs that are the actual moderation risk.
 *
 * **THE HIDE IS REVERSIBLE AND THE REASON IS REQUIRED.** A takedown somebody can
 * undo is the right shape for a judgement call made in seconds on somebody else's
 * words, and the note is the only trace of why — invisible to both the asker and
 * the merchant, because "an admin took this down and here is why" is a
 * conversation the platform has no process for.
 *
 * **NO NAVIGATION BADGE**, which is where this departs from every other
 * moderation screen on the platform. A badge counts a QUEUE, and reactive
 * moderation has none: nothing here is waiting on staff, so a number beside the
 * icon would either count every question ever asked — a figure nobody reads — or
 * invent an urgency that does not exist. Product and review moderation carry one
 * because a seller and a buyer are genuinely blocked on them; nobody is blocked
 * on this.
 *
 * A SEPARATE CLASS FROM THE SELLER RESOURCE, the codebase's repeated rule:
 * sharing one would put a platform-wide, un-scoped surface one registration
 * mistake away from a merchant's panel.
 *
 * @see docs/modules/Questions.md §7
 */
final class QuestionModerationResource extends Resource
{
    protected static ?string $model = Question::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?int $navigationSort = 7;

    protected static ?string $recordTitleAttribute = 'body';

    public static function getNavigationGroup(): string
    {
        return __('nav.catalogue');
    }

    public static function getModelLabel(): string
    {
        return __('questions.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('questions.plural');
    }

    /**
     * **GATED ON `moderate`, NOT ON `viewAny`, and the difference is not
     * cosmetic.** `question.view_any` is registered for the SELLER guard too —
     * a merchant needs it for their own answer panel — so `can('viewAny', …)`
     * is true for a seller, and gating this platform-wide screen on it would
     * have let one through anything that resolved the resource outside the admin
     * panel's routing.
     *
     * There is no read-only moderator concept here: seeing every seller's Q&A
     * and being able to hide one are the same privilege, so one ability
     * expresses both. A deviation from the work order's sketch, recorded.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('moderate', Question::class) === true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        // Editing would be the platform rewriting either a shopper's question or
        // a merchant's answer. @see the class docblock.
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        // Hiding is the takedown, and it is reversible. A destructive lever on
        // somebody else's words, taken in seconds, is the wrong shape.
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('questions.field.body'))
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('body')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->prose(),

                    Infolists\Components\TextEntry::make('product_uuid')
                        ->label(__('questions.field.product'))
                        ->state(static fn (Question $record): string => self::productTitle($record)),
                    Infolists\Components\TextEntry::make('asker_name')
                        ->label(__('questions.field.asker')),
                    Infolists\Components\TextEntry::make('store_uuid')
                        ->label(__('questions.field.seller'))
                        ->copyable(),
                ]),

            Infolists\Components\Section::make(__('questions.field.answer'))
                ->visible(static fn (Question $record): bool => $record->answer_body !== null)
                ->schema([
                    Infolists\Components\TextEntry::make('answer_body')
                        ->hiddenLabel()
                        ->prose(),
                ]),

            /*
            | THE HIDE'S OWN RECORD, and the only place the reason is ever read.
            | The next admin looking at a hidden question deserves better than a
            | blank field.
            */
            Infolists\Components\Section::make(__('questions.field.hidden'))
                ->visible(static fn (Question $record): bool => $record->isHidden())
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('hidden_at')
                        ->label(__('questions.field.hidden'))
                        ->dateTime(),
                    Infolists\Components\TextEntry::make('hidden_reason')
                        ->label(__('questions.moderation.hide_reason'))
                        ->placeholder('—'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('body')
                    ->label(__('questions.field.body'))
                    ->wrap()
                    ->limit(80)
                    ->searchable(),

                Tables\Columns\TextColumn::make('product_uuid')
                    ->label(__('questions.field.product'))
                    ->state(static fn (Question $record): string => self::productTitle($record))
                    ->wrap(),

                Tables\Columns\TextColumn::make('asker_name')
                    ->label(__('questions.field.asker')),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('questions.field.status'))
                    ->badge()
                    ->color(static fn (QuestionStatus $state): string => $state->color())
                    ->formatStateUsing(static fn (QuestionStatus $state): string => $state->label()),

                // The takedown, visible at a glance — it is orthogonal to the
                // status, so it needs its own column rather than a fourth badge.
                Tables\Columns\IconColumn::make('hidden_at')
                    ->label(__('questions.field.hidden'))
                    ->boolean()
                    ->state(static fn (Question $record): bool => $record->isHidden()),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('questions.field.asked_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('questions.field.status'))
                    ->options(QuestionStatus::options()),

                /*
                | NO DEFAULT ON EITHER FILTER — @see the class docblock. Reactive
                | moderation has no queue, so defaulting to one state would hide
                | the answered pairs that are the actual risk.
                */
                Tables\Filters\TernaryFilter::make('hidden_at')
                    ->label(__('questions.field.hidden'))
                    ->nullable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                self::hideAction(),
                self::unhideAction(),
            ])
            ->bulkActions([])
            ->emptyStateIcon('heroicon-o-chat-bubble-left-right')
            ->emptyStateHeading(__('questions.moderation.empty'))
            ->defaultSort('created_at', 'desc');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuestionModeration::route('/'),
        ];
    }

    /**
     * @return Builder<Question>
     */
    public static function getEloquentQuery(): Builder
    {
        // No tenancy scope: moderation reads across every seller, which is what
        // makes it a platform power rather than a membership one.
        return parent::getEloquentQuery();
    }

    private static function hideAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('hide')
            ->label(__('questions.moderation.hide'))
            ->icon('heroicon-o-eye-slash')
            ->color('danger')
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label(__('questions.moderation.hide_reason'))
                    // REQUIRED HERE, NULLABLE IN THE COLUMN: the rule belongs
                    // where the actor is known.
                    ->helperText(__('questions.moderation.hide_reason_hint'))
                    ->required()
                    ->maxLength(1000),
            ])
            ->visible(static fn (Question $record): bool => ! $record->isHidden()
                && auth()->user()?->can('moderate', Question::class) === true)
            ->action(static function (Question $record, array $data): void {
                app(HideQuestionAction::class)->run($record, new HideQuestionDTO(
                    hiddenBy: (int) auth()->id(),
                    reason: (string) $data['reason'],
                ));

                Notification::make()->title(__('questions.moderation.hidden_notice'))->success()->send();
            });
    }

    private static function unhideAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('unhide')
            ->label(__('questions.moderation.unhide'))
            ->icon('heroicon-o-eye')
            ->color('success')
            ->requiresConfirmation()
            // NOT "are you sure": what actually happens. A hidden ANSWERED
            // question becomes public again; a hidden pending one goes back to
            // waiting on the merchant.
            ->modalDescription(__('questions.moderation.unhide_confirm'))
            ->visible(static fn (Question $record): bool => $record->isHidden()
                && auth()->user()?->can('moderate', Question::class) === true)
            ->action(static function (Question $record): void {
                app(UnhideQuestionAction::class)->run($record);

                Notification::make()->title(__('questions.moderation.unhidden_notice'))->success()->send();
            });
    }

    /**
     * THROUGH THE CORE CONTRACT even in the panel — Questions imports no module
     * anywhere, and a Filament resource is not an exception to `LayeringTest`.
     */
    private static function productTitle(Question $question): string
    {
        $summary = app(CatalogBrowseContract::class)->productSummaries([$question->product_uuid]);

        return $summary[$question->product_uuid]['title'] ?? $question->product_uuid;
    }
}
