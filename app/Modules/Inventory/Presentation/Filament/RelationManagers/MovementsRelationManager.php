<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Filament\RelationManagers;

use App\Modules\Inventory\Domain\Enums\StockMovementType;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A stock pool's movement history (ADR-050).
 *
 * THE ANSWER TO EVERY STOCK DISPUTE. A bare counter can only say what a number
 * is now; this says how it got there — and the TYPE column is what makes a drop
 * legible, because "three fewer" could be a sale or a hold and only the ledger
 * records which.
 *
 * STRICTLY READ-ONLY, and enforced rather than styled: the movements are
 * append-only at the model (non-negotiable #9), so there is nothing here to
 * create, edit or delete and no bulk action that could rewrite the evidence.
 *
 * SHARED BY BOTH PANELS, not duplicated: the seller and the admin read the same
 * ledger and there is no admin-only column in it. Two copies would drift, and the
 * one that drifted would be the one a dispute was settled with.
 *
 * SIGNED DELTAS ARE SHOWN AS SIGNED. A seller reading "-3" understands it
 * instantly; rendering an absolute 3 would need a legend explaining which
 * direction each type moves, which is worse than a minus sign.
 */
final class MovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'movements';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('inventory.movement.plural');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('uuid')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('inventory.movement.at'))
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label(__('inventory.movement.type'))
                    ->badge()
                    ->color(fn (StockMovementType $state): string => $state->color())
                    ->formatStateUsing(fn (StockMovementType $state): string => __("enums.StockMovementType.{$state->value}")),

                Tables\Columns\TextColumn::make('on_hand_delta')
                    ->label(__('inventory.movement.on_hand_delta'))
                    // The sign IS the information.
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? "+{$state}" : (string) $state),

                Tables\Columns\TextColumn::make('reserved_delta')
                    ->label(__('inventory.movement.reserved_delta'))
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? "+{$state}" : (string) $state),

                // The caller's key — what ties a hold to the checkout that made
                // it, once Order exists to make them.
                Tables\Columns\TextColumn::make('reference')
                    ->label(__('inventory.movement.reference'))
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('note')
                    ->label(__('inventory.movement.note'))
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label(__('inventory.movement.type'))
                    ->options(fn (): array => collect(StockMovementType::cases())
                        ->mapWithKeys(fn (StockMovementType $type): array => [
                            $type->value => __("enums.StockMovementType.{$type->value}"),
                        ])
                        ->all()),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->emptyStateIcon('heroicon-o-clock')
            ->emptyStateHeading(__('inventory.movement.empty'))
            // The ledger grows without limit by design, so this reads a window of
            // it, newest first.
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(25);
    }
}
