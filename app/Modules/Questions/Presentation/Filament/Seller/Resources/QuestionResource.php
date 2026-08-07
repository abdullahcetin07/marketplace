<?php

declare(strict_types=1);

namespace App\Modules\Questions\Presentation\Filament\Seller\Resources;

use App\Core\Domain\Contracts\CatalogBrowseContract;
use App\Core\Domain\Contracts\OrganizationAuthorizationContract;
use App\Core\Domain\Contracts\StoreQueryContract;
use App\Modules\Questions\Application\Actions\AnswerQuestionAction;
use App\Modules\Questions\Domain\DTOs\AnswerQuestionDTO;
use App\Modules\Questions\Domain\Enums\QuestionStatus;
use App\Modules\Questions\Domain\Exceptions\QuestionException;
use App\Modules\Questions\Domain\Models\Question;
use App\Modules\Questions\Presentation\Filament\Seller\Resources\QuestionResource\Pages;
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
 * "Sorularım" — the questions aimed at this merchant (ADR-071).
 *
 * **ANSWERING IS PUBLISHING**, which makes this the only seller-panel surface
 * whose one action puts text on a public page. There is no draft, no preview and
 * no moderator afterwards — the answer is live the moment it is saved, and the
 * confirmation text says so rather than leaving a merchant to discover it.
 *
 * **THE TENANCY WALL IS THE QUERY, AND THE POLICY REPEATS IT.** `store_uuid` is
 * the target snapshotted at ask time, so scoping to the actor's own live stores
 * is the whole isolation — and `QuestionPolicy::answer()` asks the same question
 * again on the way in, because a panel's scope is a query somebody can get wrong
 * and the policy is the side that cannot be.
 *
 * **A HIDDEN QUESTION IS ABSENT FROM HERE TOO**, not just from the public page.
 * An admin hides abuse, and leaving it in the merchant's queue would make them
 * read it anyway — which is most of what the hide was for.
 *
 * NO CREATE, NO EDIT, NO DELETE. A merchant does not write questions, does not
 * rewrite the shopper's, and does not delete one aimed at them — that last one is
 * the suppression `question.moderate` deliberately keeps out of their hands
 * (ADR-071). Only the asker deletes their own.
 *
 * @see docs/modules/Questions.md §6
 */
final class QuestionResource extends Resource
{
    protected static ?string $model = Question::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?int $navigationSort = 13;

    protected static ?string $recordTitleAttribute = 'body';

    public static function getNavigationGroup(): string
    {
        return __('nav.orders');
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
     * What is WAITING ON THIS MERCHANT, not how many they have ever had.
     *
     * An unanswered question is a shopper deciding whether to buy, so the number
     * that matters is the one that goes to zero when the seller is done.
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = self::getEloquentQuery()->where('status', QuestionStatus::Pending)->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', Question::class) === true;
    }

    public static function canCreate(): bool
    {
        // A merchant asking themselves a question and answering it is an
        // advertisement wearing a shopper's name.
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        // Rewriting a shopper's question would leave the seller's own answer
        // attached to something the shopper never asked.
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        // @see the class docblock: deleting a question aimed at you is exactly
        // the suppression `question.moderate` keeps out of a merchant's hands.
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
                    // MASKED, the same string the public page shows: a merchant
                    // has no more need for the shopper's full name than a
                    // stranger does.
                    Infolists\Components\TextEntry::make('asker_name')
                        ->label(__('questions.field.asker')),
                    Infolists\Components\TextEntry::make('created_at')
                        ->label(__('questions.field.asked_at'))
                        ->dateTime(),
                ]),

            Infolists\Components\Section::make(__('questions.field.answer'))
                ->visible(static fn (Question $record): bool => $record->answer_body !== null)
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('answer_body')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->prose(),
                    Infolists\Components\TextEntry::make('answered_at')
                        ->label(__('questions.field.answered_at'))
                        ->dateTime(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('product_uuid')
                    ->label(__('questions.field.product'))
                    ->state(static fn (Question $record): string => self::productTitle($record))
                    ->wrap(),

                Tables\Columns\TextColumn::make('body')
                    ->label(__('questions.field.body'))
                    ->wrap()
                    ->limit(90),

                Tables\Columns\TextColumn::make('asker_name')
                    ->label(__('questions.field.asker')),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('questions.field.asked_at'))
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('questions.field.status'))
                    ->badge()
                    ->color(static fn (QuestionStatus $state): string => $state->color())
                    ->formatStateUsing(static fn (QuestionStatus $state): string => $state->label()),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('questions.field.status'))
                    ->options(QuestionStatus::options())
                    // The unanswered ones are the job; the rest is history.
                    ->default(QuestionStatus::Pending->value),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                self::answerAction(),
            ])
            // An answer is public text written to one shopper's question. It does
            // not belong on a checkbox.
            ->bulkActions([])
            ->emptyStateIcon('heroicon-o-chat-bubble-left-right')
            ->emptyStateHeading(__('questions.answer.empty'))
            // OLDEST WAITING FIRST: a shopper deciding whether to buy is not
            // helped by a queue that serves the newest question first.
            ->defaultSort('created_at', 'asc');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuestions::route('/'),
        ];
    }

    /**
     * THE TENANCY WALL (ADR-030/071).
     *
     * The question carries its target as a store uuid, so the scope is the
     * actor's own live stores — the same two-hop every seller surface uses, and
     * the same one `QuestionPolicy::answer()` repeats on the way in.
     *
     * `whereNull('hidden_at')` IS PART OF THE WALL, not a filter: an admin's hide
     * removes a question from the merchant's queue as well as from the public
     * page.
     *
     * @return Builder<Question>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Question> $query */
        $query = parent::getEloquentQuery();

        return $query
            ->whereIn('store_uuid', self::sellerStoreUuids())
            ->visibleToSeller();
    }

    /**
     * Every live store uuid the acting user's organizations own.
     *
     * A USER WHO BELONGS TO NOTHING GETS AN EMPTY TABLE, never everyone's
     * questions — `whereIn` on an empty array is what makes that true rather than
     * a special case somebody forgets.
     *
     * @return array<int, string>
     */
    public static function sellerStoreUuids(): array
    {
        $organizationIds = app(OrganizationAuthorizationContract::class)
            ->organizationIdsForUser((int) auth()->id());

        $stores = app(StoreQueryContract::class);
        $uuids = [];

        foreach ($organizationIds as $organizationId) {
            foreach (array_keys($stores->liveStoresForOrganization($organizationId)) as $storeUuid) {
                $uuids[] = (string) $storeUuid;
            }
        }

        return $uuids;
    }

    /**
     * "Cevapla" — and the answer is live immediately.
     */
    private static function answerAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('answer')
            ->label(__('questions.answer.action'))
            ->icon('heroicon-o-chat-bubble-left-ellipsis')
            ->color('primary')
            ->modalHeading(__('questions.answer.action'))
            ->modalSubmitActionLabel(__('questions.answer.action'))
            ->form([
                Forms\Components\Textarea::make('answer_body')
                    ->label(__('questions.answer.body'))
                    // NOT "are you sure": what actually happens. There is no
                    // moderator after this and no edit path before it.
                    ->helperText(__('questions.answer.body_hint'))
                    ->required()
                    ->maxLength(2000)
                    ->rows(4),
            ])
            ->visible(static fn (Question $record): bool => $record->isPending()
                && auth()->user()?->can('answer', $record) === true)
            ->action(static fn (Question $record, array $data) => self::decide(
                static fn () => app(AnswerQuestionAction::class)->run(
                    $record,
                    new AnswerQuestionDTO(
                        answerBody: (string) $data['answer_body'],
                        // WHICH COLLEAGUE, stored and never published: a Seller
                        // Employee may answer, and "who said this" is what a
                        // merchant asks when a customer disputes it.
                        answeredBy: (int) auth()->id(),
                    ),
                ),
                __('questions.answer.submitted'),
            ));
    }

    /**
     * The current catalogue title, or the uuid when the product has gone.
     *
     * THROUGH THE CORE CONTRACT even in the panel — Questions imports no module
     * anywhere, and a Filament resource is not an exception to `LayeringTest`.
     */
    private static function productTitle(Question $question): string
    {
        $summary = app(CatalogBrowseContract::class)->productSummaries([$question->product_uuid]);

        return $summary[$question->product_uuid]['title'] ?? $question->product_uuid;
    }

    /**
     * Run the answer and say what happened.
     *
     * A REFUSAL IS A WARNING, NOT AN ERROR PAGE. `notPending` means a colleague
     * answered it first — ordinary on a shared panel, and the merchant's screen
     * should say so and refresh rather than break.
     */
    private static function decide(callable $decision, string $successMessage): void
    {
        try {
            $decision();
        } catch (QuestionException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->warning()
                ->send();

            return;
        }

        Notification::make()->title($successMessage)->success()->send();
    }
}
