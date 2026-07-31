<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Filament\RelationManagers;

use App\Core\Presentation\Support\MoneyString;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Domain\Models\OrderLine;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * What was bought, as it was bought (ADR-053).
 *
 * STRICTLY READ-ONLY, AND ENFORCED RATHER THAN STYLED: the model refuses updates
 * and deletes outright, so there is nothing here to create, edit or delete and no
 * bulk action that could rewrite a financial record. The same treatment the stock
 * ledger gets, for the same reason.
 *
 * THE TITLE IS THE SNAPSHOT, NOT A LOOKUP. A seller who renamed a product last
 * month still sees, on this order, the name the customer actually bought under —
 * which is the only version that can settle a dispute.
 *
 * THE TAX RATE IS SHOWN BESIDE THE TAX, because an invoice has to show the pair,
 * and a rate read from today's bracket could no longer match the money.
 *
 * SHARED BY BOTH PANELS. There is no seller-only or admin-only column in an order
 * line, and a second copy would drift — the one that drifted being the one a
 * dispute was settled with.
 */
final class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('order.lines');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        /** @var Order $order */
        $order = $this->getOwnerRecord();
        $decimals = $order->currency->decimal_places;

        return $table
            ->recordTitleAttribute('product_title')
            ->columns([
                Tables\Columns\TextColumn::make('product_title')
                    ->label(__('order.line.product'))
                    ->description(fn (OrderLine $record): ?string => $record->variant_label)
                    ->wrap(),

                Tables\Columns\TextColumn::make('quantity')
                    ->label(__('order.line.quantity')),

                Tables\Columns\TextColumn::make('unit_price_minor')
                    ->label(__('order.line.unit_price'))
                    ->state(fn (OrderLine $record): string => MoneyString::from($record->unit_price_minor, $decimals)),

                Tables\Columns\TextColumn::make('tax_rate')
                    ->label(__('order.line.tax_rate'))
                    // As a percentage, because that is how a seller and a tax
                    // office both read it — the ratio is the storage form.
                    ->state(fn (OrderLine $record): string => '%'.rtrim(rtrim(
                        number_format(((float) $record->tax_rate) * 100, 2, ',', ''), '0'
                    ), ',')),

                Tables\Columns\TextColumn::make('line_tax_minor')
                    ->label(__('order.line.tax'))
                    ->state(fn (OrderLine $record): string => MoneyString::from($record->line_tax_minor, $decimals)),

                Tables\Columns\TextColumn::make('line_total_minor')
                    ->label(__('order.line.total'))
                    ->weight('bold')
                    ->state(fn (OrderLine $record): string => MoneyString::from($record->line_total_minor, $decimals)),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->paginated(false);
    }
}
