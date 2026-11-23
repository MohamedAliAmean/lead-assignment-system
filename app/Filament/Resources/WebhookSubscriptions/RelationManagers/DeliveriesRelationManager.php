<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookSubscriptions\RelationManagers;

use App\Enums\DeliveryStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Read-only delivery log.
 */
final class DeliveriesRelationManager extends RelationManager
{
    protected static string $relationship = 'deliveries';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime(),
                TextColumn::make('event')->badge(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (DeliveryStatus $state): string => $state->label())
                    ->color(fn (DeliveryStatus $state): string => match ($state) {
                        DeliveryStatus::Delivered => 'success',
                        DeliveryStatus::Failed => 'danger',
                        DeliveryStatus::Pending => 'warning',
                    }),
                TextColumn::make('attempts'),
                TextColumn::make('response_status')->label('HTTP')->placeholder('—'),
                TextColumn::make('last_error')->limit(60)->placeholder('—'),
                TextColumn::make('id')->label('Delivery id')->copyable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([SelectFilter::make('status')->options(DeliveryStatus::options())]);
    }
}
