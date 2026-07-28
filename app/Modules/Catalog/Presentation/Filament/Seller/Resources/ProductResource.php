<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Filament\Seller\Resources;

use App\Core\Domain\Contracts\OrganizationAuthorizationContract;
use App\Modules\Catalog\Application\Actions\SubmitProductForReviewAction;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Presentation\Filament\Seller\Resources\ProductResource\Pages;
use App\Modules\Catalog\Presentation\Filament\Seller\Resources\ProductResource\RelationManagers;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Ürün aç" — the seller's product-authoring surface (§5, entry point 2).
 *
 * THE PRODUCT IS THE REQUEST. There is no separate request entity: the seller
 * drafts a product, submits it, and a Category Manager's approval simply moves
 * it to Published. Nothing here publishes anything.
 *
 * MEMBERSHIP-SCOPED (ADR-030/040). `getEloquentQuery()` is the tenancy wall,
 * confined to the organizations the actor actively belongs to, resolved through
 * the Core `OrganizationAuthorizationContract` — so Catalog never imports an
 * Organization model and one seller can never see another's proposal.
 *
 * WHAT IS DELIBERATELY ABSENT: any way to select an existing catalog product to
 * sell, and any price or stock field. That is the seller's OTHER entry point
 * (§5, entry point 1) and it is an Offer — Phase 2. Surfacing it here would
 * imply a store↔product link the platform has not defined.
 *
 * The seller is told, on the form, that the catalog is shared: a product they
 * open becomes the platform's and other sellers may sell it too (ADR-037). That
 * is surprising if you have used Shopify, and finding out after approval is
 * worse than reading it before.
 *
 * @see App\Modules\Catalog\Presentation\Filament\Resources\ProductModerationResource
 */
final class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?int $navigationSort = 10;

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
        return __('catalog.product.plural');
    }

    /**
     * The panel is seller-guarded and the query is membership-scoped, so the
     * listing is open to any panel user; per-record authorization stays in
     * ProductPolicy, which every row action consults.
     */
    public static function canViewAny(): bool
    {
        return true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', Product::class) === true
            && self::sellerOrganizations() !== [];
    }

    /**
     * Editing follows the LIFECYCLE, not merely ownership: a product under
     * review must not change beneath the moderator reading it, and a published
     * one belongs to the shared catalog (§3.1, ADR-037).
     */
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return auth()->user()?->can('update', $record) === true;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        // Abandoning a proposal is archiving it — the row stays in the
        // moderation record (§3.5).
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->description(__('catalog.product.shared_notice'))
                ->schema([
                    /*
                    | WHICH COMPANY IS PROPOSING (ADR-030 — a seller may belong
                    | to several).
                    |
                    | Hidden entirely in the overwhelmingly common case of one
                    | organization, and defaulted: asking someone to pick from a
                    | list of one is a question with no information in it.
                    |
                    | When there ARE several, the options are labelled by id
                    | rather than by legal name — Catalog may not import an
                    | Organization model to resolve names (ADR-040), and the Core
                    | contract answers memberships in ids. Recorded as a
                    | documented Phase-1 limitation in Catalog.md §5 rather than
                    | quietly reaching across the boundary for a label.
                    */
                    Forms\Components\Select::make('proposed_by_org_id')
                        ->label(__('catalog.product.organization'))
                        ->helperText(__('catalog.product.organization_hint'))
                        ->options(fn (): array => self::sellerOrganizationOptions())
                        ->default(fn (): ?int => array_key_first(self::sellerOrganizationOptions()))
                        ->required()
                        ->native(false)
                        ->hidden(fn (): bool => count(self::sellerOrganizations()) < 2)
                        // Provenance is fixed at creation: moving a proposal
                        // between companies after the fact would rewrite who
                        // may edit it mid-review.
                        ->disabledOn('edit'),

                    Forms\Components\Select::make('category_id')
                        ->label(__('catalog.product.category'))
                        ->helperText(__('catalog.product.category_hint'))
                        // LEAVES ONLY (§3.2) — the schema a product must satisfy
                        // comes from its category, and a container has none.
                        ->options(fn (): array => self::leafCategoryOptions())
                        ->searchable()
                        ->required()
                        ->native(false)
                        ->live(),

                    Forms\Components\TextInput::make('title_tr')
                        ->label(__('catalog.product.title').' (TR)')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('title_en')
                        ->label(__('catalog.product.title').' (EN)')
                        ->maxLength(255),

                    Forms\Components\Select::make('brand_id')
                        ->label(__('catalog.product.brand'))
                        ->placeholder(__('catalog.product.brand_none'))
                        ->options(fn (): array => Brand::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->native(false),

                    Forms\Components\TextInput::make('gtin')
                        ->label(__('catalog.product.gtin'))
                        ->helperText(__('catalog.product.gtin_hint'))
                        ->maxLength(14),

                    Forms\Components\Textarea::make('description_tr')
                        ->label(__('catalog.product.description').' (TR)')
                        ->rows(4)
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('description_en')
                        ->label(__('catalog.product.description').' (EN)')
                        ->rows(4)
                        ->columnSpanFull(),
                ])
                ->columns(2),

            /*
            | Images are NOT a form field. `filament/spatie-laravel-media-library-plugin`
            | is not a dependency of this project, and adding one to render an
            | upload box is the wrong trade — so uploads go through a dedicated
            | action on the edit page, which calls `AttachProductMediaAction`.
            | That is better regardless: the media write travels the same path as
            | every other write in the module instead of bypassing it.
            | @see App\Modules\Catalog\Presentation\Filament\Seller\Resources\ProductResource\Pages\EditProduct
            */
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label('')
                    // Straight off the model helper: no plugin, and the URL
                    // rule (public disk, `thumb` conversion) stays in one place.
                    ->getStateUsing(fn (Product $record): ?string => $record->imageUrl('thumb')),

                Tables\Columns\TextColumn::make('title_tr')
                    ->label(__('catalog.product.title'))
                    ->searchable(['title_tr', 'title_en'])
                    ->formatStateUsing(fn (Product $record): string => $record->localized('title'))
                    ->description(fn (Product $record): string => $record->category->localized('name')),

                Tables\Columns\TextColumn::make('variants_count')
                    ->label(__('catalog.product.variants_count'))
                    ->counts('variants'),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('catalog.product.status'))
                    ->badge()
                    ->color(fn (Product $record): string => $record->status->color())
                    ->formatStateUsing(fn (Product $record): string => $record->status->label()),

                // The moderator's note. The single most useful column on this
                // table when a product comes back — without it the seller sees
                // "Needs revision" and has to open the record to learn why.
                Tables\Columns\TextColumn::make('moderation_reason')
                    ->label(__('catalog.product.moderation_reason'))
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('submitted_at')
                    ->label(__('catalog.product.submitted_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('catalog.product.status'))
                    ->options(ProductStatus::options()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                self::submitAction(),
            ])
            ->bulkActions([])
            ->emptyStateIcon('heroicon-o-cube')
            ->emptyStateHeading(__('catalog.product.empty.heading'))
            ->emptyStateDescription(__('catalog.product.empty.description'))
            ->defaultSort('id', 'desc');
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            RelationManagers\AttributesRelationManager::class,
            RelationManagers\VariantsRelationManager::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }

    /**
     * THE TENANCY WALL (ADR-030/040).
     *
     * Confined to the organizations the actor actively belongs to, by
     * `proposed_by_org_id`, resolved through the Core authorization contract —
     * never by importing an Organization model. A seller who belongs to nothing
     * gets an empty table, never everyone's catalog; `scopeProposedByAny()`
     * owns that rule.
     *
     * @return Builder<Product>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Product> $query */
        $query = parent::getEloquentQuery();

        return $query
            ->with(['category', 'brand'])
            ->withCount('variants')
            ->proposedByAny(self::sellerOrganizations());
    }

    /**
     * The ids of every organization the actor actively belongs to.
     *
     * @return array<int, int>
     */
    public static function sellerOrganizations(): array
    {
        $userId = auth()->id();

        if ($userId === null) {
            return [];
        }

        return app(OrganizationAuthorizationContract::class)->organizationIdsForUser((int) $userId);
    }

    /**
     * Leaf categories, indented so the picker reads as a tree (§3.2).
     *
     * @return array<int, string>
     */
    public static function leafCategoryOptions(): array
    {
        return Category::query()
            ->leaves()
            ->active()
            ->orderBy('path')
            ->get()
            ->mapWithKeys(fn (Category $category): array => [
                $category->getKey() => str_repeat('— ', max(0, $category->depth - 1)).$category->localized('name'),
            ])
            ->all();
    }

    /**
     * The companies the actor may propose on behalf of, as select options.
     *
     * Labelled by id: resolving a legal name would mean importing an
     * Organization model, which ADR-040 forbids outright. The field is hidden
     * whenever there is only one, so this shows only in the multi-company case
     * — a documented Phase-1 limitation (Catalog.md §5), not an oversight.
     *
     * @return array<int, string>
     */
    private static function sellerOrganizationOptions(): array
    {
        $options = [];

        foreach (self::sellerOrganizations() as $organizationId) {
            $options[$organizationId] = '#'.$organizationId;
        }

        return $options;
    }

    /**
     * Draft → the moderation queue. The action re-checks the transition and
     * that the product has at least one variant; both are EXPECTED refusals, so
     * they surface as a warning rather than an error page.
     */
    private static function submitAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('submit')
            ->label(__('catalog.product.action.submit'))
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->requiresConfirmation()
            ->modalDescription(__('catalog.product.action.submit_confirm'))
            ->visible(fn (Product $record): bool => $record->status->canTransitionTo(ProductStatus::PendingReview)
                && auth()->user()?->can('submit', $record) === true)
            ->action(function (Product $record): void {
                try {
                    app(SubmitProductForReviewAction::class)->run($record);
                } catch (CatalogException $exception) {
                    Notification::make()
                        ->title(__('catalog.product.notify.failed'))
                        ->body($exception->getMessage())
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()->title(__('catalog.product.notify.submitted'))->success()->send();
            });
    }
}
