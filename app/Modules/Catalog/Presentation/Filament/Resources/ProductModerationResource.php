<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Resources;

use App\Modules\Catalog\Application\Actions\ArchiveProductAction;
use App\Modules\Catalog\Application\Actions\PublishProductAction;
use App\Modules\Catalog\Application\Actions\RejectProductAction;
use App\Modules\Catalog\Application\Actions\RequestProductRevisionAction;
use App\Modules\Catalog\Domain\DTOs\ModerationDecisionDTO;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Presentation\Filament\Resources\ProductModerationResource\Pages;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The product moderation queue — the Category Manager's day-to-day surface (§5).
 *
 * READ AND DECIDE, NOT EDIT. The three verdicts are the only writes here:
 * approve, reject, request a revision. There is no create and no edit form,
 * because a moderator changing a product to make it acceptable removes the
 * seller's chance to learn what was wrong — sending it back with a reason is the
 * designed path, and it is why `NeedsRevision` exists at all.
 *
 * THE QUEUE IS THE DEFAULT VIEW but the resource lists every product, with a
 * status filter: a moderator asked "did we ever publish X" should not have to
 * leave the screen. `Product::scopeAwaitingModeration()` owns the queue's own
 * rule so this and the tests cannot drift.
 *
 * WHY A SEPARATE CLASS from the seller's `ProductResource`: that one is scoped
 * to one company's proposals and gated on authoring; this one reads across every
 * seller and holds the verdicts. Sharing them would put a cross-org admin
 * surface one registration mistake away from a seller panel.
 *
 * @see App\Modules\Catalog\Presentation\Filament\Seller\Resources\ProductResource
 */
final class ProductModerationResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'title_tr';

    public static function getNavigationGroup(): string
    {
        return __('nav.catalogue');
    }

    public static function getModelLabel(): string
    {
        return __('catalog.product.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('catalog.moderation.queue');
    }

    /**
     * The count a Category Manager actually wants on the sidebar: how many are
     * waiting, not how many exist.
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = Product::query()->whereStatus(ProductStatus::PendingReview)->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', Product::class) === true;
    }

    public static function canCreate(): bool
    {
        // Products enter the catalog by being proposed (§5). Staff curate what
        // arrives; they do not author from the queue.
        return false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        // Archived is the end state (§3.5); a product Offers will reference is
        // never destroyed.
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * Everything a moderator needs to judge the proposal, on one screen: the
     * localized copy, the taxonomy placement, the attributes, every variant and
     * the gallery. A verdict made without seeing the variants is a guess.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('catalog.product.singular'))->schema([
                Infolists\Components\TextEntry::make('title_tr')
                    ->label(__('catalog.product.title').' (TR)'),

                Infolists\Components\TextEntry::make('title_en')
                    ->label(__('catalog.product.title').' (EN)')
                    ->placeholder('—'),

                Infolists\Components\TextEntry::make('category.name_tr')
                    ->label(__('catalog.product.category'))
                    ->formatStateUsing(fn (Product $record): string => $record->category->localized('name')),

                Infolists\Components\TextEntry::make('brand.name')
                    ->label(__('catalog.product.brand'))
                    ->placeholder(__('catalog.product.brand_none')),

                Infolists\Components\TextEntry::make('gtin')
                    ->label(__('catalog.product.gtin'))
                    ->placeholder('—'),

                Infolists\Components\TextEntry::make('status')
                    ->label(__('catalog.product.status'))
                    ->badge()
                    ->color(fn (Product $record): string => $record->status->color())
                    ->formatStateUsing(fn (Product $record): string => $record->status->label()),

                Infolists\Components\TextEntry::make('description_tr')
                    ->label(__('catalog.product.description'))
                    ->placeholder('—')
                    ->columnSpanFull(),
            ])->columns(3),

            Infolists\Components\Section::make(__('catalog.product.attributes'))->schema([
                Infolists\Components\RepeatableEntry::make('descriptiveAttributes')
                    ->hiddenLabel()
                    ->schema([
                        Infolists\Components\TextEntry::make('code')->label(__('catalog.attribute.code')),
                        Infolists\Components\TextEntry::make('pivot.value')
                            ->label(__('catalog.attribute.value'))
                            ->placeholder('—'),
                    ])
                    ->columns(2),
            ])->collapsible(),

            Infolists\Components\Section::make(__('catalog.product.variants'))->schema([
                Infolists\Components\RepeatableEntry::make('variants')
                    ->hiddenLabel()
                    ->schema([
                        Infolists\Components\TextEntry::make('sku')->label(__('catalog.variant.sku')),
                        Infolists\Components\TextEntry::make('combination_key')
                            ->label(__('catalog.variant.combination'))
                            ->formatStateUsing(fn (ProductVariant $record): string => $record->combinationLabel()),
                        Infolists\Components\TextEntry::make('barcode')
                            ->label(__('catalog.variant.barcode'))
                            ->placeholder('—'),
                    ])
                    ->columns(3),
            ]),

            Infolists\Components\Section::make(__('catalog.product.images'))->schema([
                Infolists\Components\ImageEntry::make('gallery')
                    ->hiddenLabel()
                    // The gallery a moderator must see before deciding. Read
                    // through the model helper rather than a Filament media
                    // plugin, which is not a dependency here.
                    ->getStateUsing(fn (Product $record): array => collect($record->imageGallery())
                        ->pluck('preview')
                        ->all()),
            ])->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label('')
                    ->getStateUsing(fn (Product $record): ?string => $record->imageUrl('thumb')),

                Tables\Columns\TextColumn::make('title_tr')
                    ->label(__('catalog.product.title'))
                    ->searchable(['title_tr', 'title_en'])
                    ->formatStateUsing(fn (Product $record): string => $record->localized('title'))
                    ->description(fn (Product $record): string => $record->category->localized('name')),

                Tables\Columns\TextColumn::make('brand.name')
                    ->label(__('catalog.product.brand'))
                    ->placeholder(__('catalog.product.brand_none'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('variants_count')
                    ->label(__('catalog.product.variants_count'))
                    ->counts('variants'),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('catalog.product.status'))
                    ->badge()
                    ->color(fn (Product $record): string => $record->status->color())
                    ->formatStateUsing(fn (Product $record): string => $record->status->label()),

                Tables\Columns\TextColumn::make('submitted_at')
                    ->label(__('catalog.product.submitted_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('catalog.product.status'))
                    ->options(ProductStatus::options())
                    // The queue is the default; everything else is one click.
                    ->default(ProductStatus::PendingReview->value),

                Tables\Filters\SelectFilter::make('brand_id')
                    ->label(__('catalog.product.brand'))
                    ->options(fn (): array => Brand::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                self::publishAction(),
                self::requestRevisionAction(),
                self::rejectAction(),
                self::archiveAction(),
            ])
            ->bulkActions([])
            ->emptyStateIcon('heroicon-o-clipboard-document-check')
            ->emptyStateHeading(__('catalog.product.empty.queue_heading'))
            ->emptyStateDescription(__('catalog.product.empty.queue_description'))
            // Oldest submission first: a queue that shows the newest first
            // starves the seller who has waited longest.
            ->defaultSort('submitted_at', 'asc');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductModeration::route('/'),
            'view' => Pages\ViewProductModeration::route('/{record}'),
        ];
    }

    /**
     * @return Builder<Product>
     */
    public static function getEloquentQuery(): Builder
    {
        // No tenancy scope: moderation reads across every seller, which is what
        // makes it a platform power rather than a membership one.
        return parent::getEloquentQuery()->with(['category', 'brand'])->withCount('variants');
    }

    /**
     * Approve. Schema conformance is checked by the action (§3.2) — a missing
     * required attribute surfaces here as a warning naming the attribute, so the
     * moderator can send it back with something actionable.
     */
    private static function publishAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('publish')
            ->label(__('catalog.product.action.publish'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('catalog.product.action.publish_confirm'))
            ->visible(fn (Product $record): bool => $record->status->awaitsModeration()
                && auth()->user()?->can('publish', $record) === true)
            ->action(fn (Product $record) => self::decide(
                fn () => app(PublishProductAction::class)->run($record, new ModerationDecisionDTO(
                    moderatedBy: (int) auth()->id(),
                )),
                __('catalog.product.notify.published'),
            ));
    }

    /**
     * Send back with a reason — the humane middle path (§3.1). The reason is
     * required by the action and shown to the seller, so the form requires it
     * too rather than letting a blank submit bounce.
     */
    private static function requestRevisionAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('request_revision')
            ->label(__('catalog.product.action.request_revision'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label(__('catalog.product.reason'))
                    ->helperText(__('catalog.product.reason_revision_hint'))
                    ->required()
                    ->maxLength(1000)
                    ->rows(3),
            ])
            ->visible(fn (Product $record): bool => $record->status->awaitsModeration()
                && auth()->user()?->can('requestRevision', $record) === true)
            ->action(fn (Product $record, array $data) => self::decide(
                fn () => app(RequestProductRevisionAction::class)->run($record, new ModerationDecisionDTO(
                    reason: (string) $data['reason'],
                    moderatedBy: (int) auth()->id(),
                )),
                __('catalog.product.notify.revision_requested'),
            ));
    }

    private static function rejectAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('reject')
            ->label(__('catalog.product.action.reject'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label(__('catalog.product.reason'))
                    ->helperText(__('catalog.product.reason_reject_hint'))
                    ->required()
                    ->maxLength(1000)
                    ->rows(3),
            ])
            ->visible(fn (Product $record): bool => $record->status->awaitsModeration()
                && auth()->user()?->can('reject', $record) === true)
            ->action(fn (Product $record, array $data) => self::decide(
                fn () => app(RejectProductAction::class)->run($record, new ModerationDecisionDTO(
                    reason: (string) $data['reason'],
                    moderatedBy: (int) auth()->id(),
                )),
                __('catalog.product.notify.rejected'),
            ));
    }

    private static function archiveAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('archive')
            ->label(__('catalog.product.action.archive'))
            ->icon('heroicon-o-archive-box')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('catalog.product.action.archive_confirm'))
            ->visible(fn (Product $record): bool => $record->status->canTransitionTo(ProductStatus::Archived)
                && auth()->user()?->can('archive', $record) === true)
            ->action(fn (Product $record) => self::decide(
                fn () => app(ArchiveProductAction::class)->run($record),
                __('catalog.product.notify.archived'),
            ));
    }

    /**
     * One place for the verdict-then-notify shape.
     *
     * A `CatalogException` here is an EXPECTED refusal — "this product is
     * missing an attribute its category requires" — so it becomes a warning
     * notification carrying the reason, never a 500.
     *
     * @param  callable(): mixed  $decision
     */
    private static function decide(callable $decision, string $successMessage): void
    {
        try {
            $decision();
        } catch (CatalogException $exception) {
            Notification::make()
                ->title(__('catalog.product.notify.failed'))
                ->body($exception->getMessage())
                ->warning()
                ->send();

            return;
        }

        Notification::make()->title($successMessage)->success()->send();
    }
}
