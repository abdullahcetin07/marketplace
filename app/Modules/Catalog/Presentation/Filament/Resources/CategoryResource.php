<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Resources;

use App\Modules\Catalog\Application\Actions\ArchiveCategoryAction;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Presentation\Filament\Resources\CategoryResource\Pages;
use App\Modules\Catalog\Presentation\Filament\Resources\CategoryResource\RelationManagers;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The category tree, on the ADMIN panel only (ADR-038).
 *
 * THE TREE IS RENDERED AS AN INDENTED LIST ordered by `path`, not as a nested
 * widget. Sorting by the materialised path puts every node directly under its
 * parent for free — the same column the descendant queries use — and it keeps
 * the whole taxonomy scannable and searchable in one table, which a collapsible
 * tree of 400 nodes is not.
 *
 * NOT REGISTERED ON THE SELLER PANEL. A seller reads categories through the
 * product form's select and can never write one; that is enforced by the
 * permission not existing on the seller guard, and by this class simply not
 * appearing in SellerPanelProvider.
 *
 * @see App\Modules\Catalog\Presentation\Policies\CategoryPolicy
 */
final class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name_tr';

    public static function getNavigationGroup(): string
    {
        return __('nav.catalogue');
    }

    public static function getModelLabel(): string
    {
        return __('catalog.category.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('catalog.category.plural');
    }

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                // One field per catalog locale (§13.5). Turkish is required
                // because it is the authoring locale; English may be filled in
                // later, and `localized()` falls back rather than rendering a
                // blank row.
                Forms\Components\TextInput::make('name_tr')
                    ->label(__('catalog.category.name').' (TR)')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('name_en')
                    ->label(__('catalog.category.name').' (EN)')
                    ->maxLength(255),

                Forms\Components\Select::make('parent_id')
                    ->label(__('catalog.category.parent'))
                    ->placeholder(__('catalog.category.parent_none'))
                    ->options(fn (?Category $record): array => self::parentOptions($record))
                    ->searchable()
                    ->native(false),

                Forms\Components\TextInput::make('slug')
                    ->label(__('catalog.category.slug'))
                    ->helperText(__('catalog.category.slug_hint'))
                    ->maxLength(255)
                    ->rule('alpha_dash'),

                Forms\Components\Toggle::make('is_active')
                    ->label(__('catalog.category.is_active'))
                    ->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name_tr')
                    ->label(__('catalog.category.name'))
                    ->searchable(['name_tr', 'name_en'])
                    // The indent is what makes a flat table read as a tree.
                    ->formatStateUsing(fn (Category $record): string => str_repeat('— ', $record->depth).$record->localized('name')),

                Tables\Columns\TextColumn::make('slug')
                    ->label(__('catalog.category.slug'))
                    ->toggleable()
                    ->searchable(),

                Tables\Columns\IconColumn::make('is_leaf')
                    ->label(__('catalog.category.is_leaf'))
                    ->boolean()
                    // Products attach to leaves only (§3.2), so "can I file a
                    // product here" is the single most useful thing this table
                    // can tell a Category Manager at a glance.
                    ->getStateUsing(fn (Category $record): bool => $record->isLeaf()),

                Tables\Columns\TextColumn::make('products_count')
                    ->label(__('catalog.category.products_count'))
                    ->counts('products')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('catalog.category.is_active'))
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(__('catalog.category.is_active')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                self::archiveAction(),
            ])
            ->bulkActions([])
            ->emptyStateIcon('heroicon-o-rectangle-stack')
            ->emptyStateHeading(__('catalog.category.empty.heading'))
            ->emptyStateDescription(__('catalog.category.empty.description'))
            // The materialised path IS the tree order.
            ->defaultSort('path')
            ->paginated([50, 100, 'all']);
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            RelationManagers\AttributesRelationManager::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit' => Pages\EditCategory::route('/{record}/edit'),
        ];
    }

    /**
     * @return Builder<Category>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('products');
    }

    /**
     * Deactivation, never deletion (ADR-015). The action refuses a branch with
     * active children — an expected refusal, so it surfaces as a warning
     * notification rather than an error page.
     */
    private static function archiveAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('archive')
            ->label(__('catalog.category.action.archive'))
            ->icon('heroicon-o-archive-box')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('catalog.category.action.archive_confirm'))
            ->visible(fn (Category $record): bool => $record->is_active
                && auth()->user()?->can('archive', $record) === true)
            ->action(function (Category $record): void {
                try {
                    app(ArchiveCategoryAction::class)->run($record);
                } catch (CatalogException $exception) {
                    Notification::make()
                        ->title(__('catalog.product.notify.failed'))
                        ->body($exception->getMessage())
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()->title(__('catalog.category.notify.archived'))->success()->send();
            });
    }

    /**
     * Candidate parents: everything except the node itself and its own
     * descendants — the cycle the action refuses, kept out of the picker so a
     * Category Manager is never offered a choice that will be rejected.
     *
     * @return array<int, string>
     */
    private static function parentOptions(?Category $record): array
    {
        return Category::query()
            ->unless($record === null, fn (Builder $query): Builder => $query
                ->whereKeyNot($record->getKey())
                // Exclude the node's own subtree: moving into a descendant is
                // the cycle the action refuses, so never offer it.
                ->where('path', 'not like', $record->path.'%'))
            ->orderBy('path')
            ->get()
            ->mapWithKeys(fn (Category $category): array => [
                $category->getKey() => str_repeat('— ', $category->depth).$category->localized('name'),
            ])
            ->all();
    }
}
