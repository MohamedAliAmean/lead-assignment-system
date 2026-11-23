<?php

declare(strict_types=1);

namespace App\Filament\Resources\Teams\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class TeamsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('manager:id,name')->withCount('members'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('manager.name')->placeholder('—'),
                TextColumn::make('members_count')->label('Members'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
