<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookSubscriptions\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class WebhookSubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('deliveries'))
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('url')->limit(50),
                TextColumn::make('events')->badge(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('deliveries_count')->label('Deliveries'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
