<?php

declare(strict_types=1);

namespace App\Filament\Resources\Teams\Schemas;

use App\Enums\UserRole;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class TeamForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(100)->unique(ignoreRecord: true),
            Select::make('manager_id')
                ->label('Manager')
                ->relationship('manager', 'name', fn (Builder $query): Builder => $query->where('role', UserRole::SalesManager->value))
                ->unique(ignoreRecord: true)
                ->searchable()
                ->preload(),
        ]);
    }
}
