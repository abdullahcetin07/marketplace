<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Presentation\Filament\Resources;

use App\Core\Domain\Contracts\CatalogBrowseContract;
use App\Modules\Reviews\Application\Actions\PublishReviewAction;
use App\Modules\Reviews\Application\Actions\RejectReviewAction;
use App\Modules\Reviews\Domain\DTOs\ReviewModerationDTO;
use App\Modules\Reviews\Domain\Enums\ReviewStatus;
use App\Modules\Reviews\Domain\Exceptions\ReviewException;
use App\Modules\Reviews\Domain\Models\Review;
use App\Modules\Reviews\Presentation\Filament\Resources\ReviewModerationResource\Pages;
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
 * The review queue — where a buyer's words become public, or do not (ADR-068).
 *
 * **READ AND DECIDE, NOT EDIT.** Two verdicts are the only writes: publish or
 * reject. There is no edit form and there will not be one, because a moderator
 * who could fix a review would be rewriting somebody's opinion under their name.
 * That is the difference from Catalog's product queue, which has a
 * `NeedsRevision` precisely because a SELLER wants their listing published and
 * will fix it.
 *
 * **OLDEST FIRST, PENDING BY DEFAULT.** An unanswered review costs a buyer
 * nothing but their patience and costs the product its rating — a queue sorted
 * newest-first quietly starves the oldest submission forever.
 *
 * **THE PHOTOS ARE ON THE SCREEN, and that is a requirement rather than a
 * nicety** (ADR-068, Reviews.md §4): there is no separate photo-moderation step,
 * so the review and its images are approved or refused as one unit. A moderator
 * publishing without seeing them is approving something nobody looked at.
 *
 * **THE SELLER CANNOT REACH THIS RESOURCE**, and not because it is hidden: it is
 * registered in the ADMIN panel only, and `canViewAny()` asks a permission no
 * seller guard can hold. The party a review judges gets no lever over it.
 *
 * NO TENANCY SCOPE on the query, deliberately — moderation reads across every
 * seller, which is what makes it a platform power rather than a membership one.
 *
 * @see docs/modules/Reviews.md §6
 */
final class ReviewModerationResource extends Resource
{
    protected static ?string $model = Review::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'author_name';

    public static function getNavigationGroup(): string
    {
        return __('nav.catalogue');
    }

    public static function getModelLabel(): string
    {
        return __('reviews.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('reviews.plural');
    }

    /**
     * What is WAITING, not how many exist.
     *
     * A badge counting every review ever written settles at a number nobody
     * reads. This one is zero when the queue is clear, which is the only state
     * worth showing at a glance.
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = Review::query()->where('status', ReviewStatus::PendingReview)->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', Review::class) === true;
    }

    public static function canCreate(): bool
    {
        // A review is written by a buyer who was delivered the goods (ADR-067).
        // Staff moderating one they authored would be the platform reviewing
        // itself.
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        // @see the class docblock: fixing a review is rewriting an opinion under
        // somebody else's name.
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        // Rejection is the removal (ADR-068), and it keeps the row: the buyer
        // must still see what happened, and the line must stay spent or the
        // refused text comes straight back through eligibility.
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * Everything needed to judge it, on one screen.
     *
     * THE PRODUCT IS RESOLVED THROUGH THE CORE CONTRACT — Reviews may not import
     * Catalog even here, so the title is fetched by uuid rather than joined.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('reviews.plural'))
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('rating')
                        ->label(__('reviews.field.rating'))
                        ->formatStateUsing(static fn (int $state): string => str_repeat('★', $state).str_repeat('☆', 5 - $state)),
                    Infolists\Components\TextEntry::make('status')
                        ->label(__('reviews.field.status'))
                        ->badge()
                        ->color(static fn (ReviewStatus $state): string => $state->color())
                        ->formatStateUsing(static fn (ReviewStatus $state): string => $state->label()),
                    Infolists\Components\TextEntry::make('created_at')
                        ->label(__('reviews.field.submitted_at'))
                        ->dateTime(),

                    Infolists\Components\TextEntry::make('body')
                        ->label(__('reviews.field.body'))
                        ->columnSpanFull()
                        ->placeholder('—')
                        ->prose(),
                ]),

            /*
            | THE GALLERY. There is no separate photo-moderation step (ADR-068),
            | so this is the only place anybody looks at what is about to be
            | published — approving without it is approving unseen.
            */
            Infolists\Components\Section::make(__('reviews.field.photos'))
                ->visible(static fn (Review $record): bool => $record->has_photos)
                ->schema([
                    Infolists\Components\ImageEntry::make('images')
                        ->hiddenLabel()
                        ->state(static fn (Review $record): array => array_map(
                            static fn (array $image): string => (string) $image['preview'],
                            $record->imageGallery(),
                        )),
                ]),

            Infolists\Components\Section::make(__('reviews.field.product'))
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('product_uuid')
                        ->label(__('reviews.field.product'))
                        ->state(static fn (Review $record): string => self::productTitle($record)),
                    Infolists\Components\TextEntry::make('store_uuid')
                        ->label(__('reviews.field.seller'))
                        ->copyable(),
                    // MASKED, the same string the public page shows. A moderator
                    // has no more need for the buyer's full name than a shopper.
                    Infolists\Components\TextEntry::make('author_name')
                        ->label(__('reviews.field.author')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('product_uuid')
                    ->label(__('reviews.field.product'))
                    ->state(static fn (Review $record): string => self::productTitle($record))
                    ->wrap(),

                Tables\Columns\TextColumn::make('rating')
                    ->label(__('reviews.field.rating'))
                    ->formatStateUsing(static fn (int $state): string => str_repeat('★', $state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('author_name')
                    ->label(__('reviews.field.author')),

                Tables\Columns\IconColumn::make('has_photos')
                    ->label(__('reviews.field.has_photos'))
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('reviews.field.submitted_at'))
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('reviews.field.status'))
                    ->badge()
                    ->color(static fn (ReviewStatus $state): string => $state->color())
                    ->formatStateUsing(static fn (ReviewStatus $state): string => $state->label()),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('reviews.field.status'))
                    ->options(ReviewStatus::options())
                    // The queue is the default; everything else is one click.
                    ->default(ReviewStatus::PendingReview->value),

                /*
                | THE "SADECE RESİMLİ" MODERATION VIEW. Photos are the part of a
                | review that cannot be skim-read, and they are the part that can
                | be genuinely unacceptable — a moderator with ten minutes should
                | be able to spend them where the risk is.
                */
                Tables\Filters\TernaryFilter::make('has_photos')
                    ->label(__('reviews.field.has_photos')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                self::publishAction(),
                self::rejectAction(),
            ])
            // A verdict on somebody's words does not belong on a checkbox.
            ->bulkActions([])
            ->emptyStateIcon('heroicon-o-star')
            ->emptyStateHeading(__('reviews.moderation.empty'))
            // OLDEST FIRST — @see the class docblock.
            ->defaultSort('created_at', 'asc');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReviewModeration::route('/'),
        ];
    }

    /**
     * @return Builder<Review>
     */
    public static function getEloquentQuery(): Builder
    {
        // No tenancy scope: moderation reads across every seller.
        return parent::getEloquentQuery();
    }

    private static function publishAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('publish')
            ->label(__('reviews.moderation.publish'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('reviews.moderation.publish_confirm'))
            ->visible(static fn (Review $record): bool => $record->awaitsModeration()
                && auth()->user()?->can('moderate', Review::class) === true)
            ->action(static fn (Review $record) => self::decide(
                static fn () => app(PublishReviewAction::class)->run(
                    $record,
                    new ReviewModerationDTO(moderatedBy: (int) auth()->id()),
                ),
                __('reviews.moderation.published_notice'),
            ));
    }

    private static function rejectAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('reject')
            ->label(__('reviews.moderation.reject'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label(__('reviews.moderation.reject_reason'))
                    // REQUIRED HERE, NULLABLE IN THE COLUMN: the rule belongs
                    // where the actor is known. It is kept for the internal
                    // record and never shown to the buyer (Reviews.md §6).
                    ->helperText(__('reviews.moderation.reject_reason_hint'))
                    ->required()
                    ->maxLength(1000),
            ])
            ->visible(static fn (Review $record): bool => $record->awaitsModeration()
                && auth()->user()?->can('moderate', Review::class) === true)
            ->action(static fn (Review $record, array $data) => self::decide(
                static fn () => app(RejectReviewAction::class)->run(
                    $record,
                    new ReviewModerationDTO(
                        moderatedBy: (int) auth()->id(),
                        reason: (string) $data['reason'],
                    ),
                ),
                __('reviews.moderation.rejected_notice'),
            ));
    }

    /**
     * The current catalogue title, or the uuid when the product has gone.
     *
     * THROUGH THE CORE CONTRACT even in the panel — Reviews imports no module
     * anywhere, and a Filament resource is not an exception to `LayeringTest`.
     */
    private static function productTitle(Review $review): string
    {
        $summary = app(CatalogBrowseContract::class)->productSummaries([$review->product_uuid]);

        return $summary[$review->product_uuid]['title'] ?? $review->product_uuid;
    }

    /**
     * Run a verdict and say what happened.
     *
     * A REFUSED VERDICT IS A WARNING, NOT AN ERROR PAGE. `notPending` means a
     * colleague answered it first — ordinary on a shared queue, and the
     * moderator's screen should say so and refresh rather than break.
     */
    private static function decide(callable $decision, string $successMessage): void
    {
        try {
            $decision();
        } catch (ReviewException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->warning()
                ->send();

            return;
        }

        Notification::make()->title($successMessage)->success()->send();
    }
}
