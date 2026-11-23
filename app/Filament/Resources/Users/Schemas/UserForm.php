<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Account')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                TextInput::make('password')->password()->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->minLength(8),
                Select::make('role')->options(UserRole::options())->required()->native(false)
                    ->default(UserRole::SalesAgent->value),
            ]),
            Section::make('Sales settings')->columns(2)->schema([
                Select::make('team_id')->label('Team')->relationship('team', 'name')->searchable()->preload(),
                Toggle::make('is_active')->label('Active')->default(true)
                    ->helperText('Inactive users cannot sign in and never receive leads.'),
                TextInput::make('daily_capacity')->numeric()->required()->minValue(0)->maxValue(1000)
                    ->default(fn (): int => (int) config('leads.default_daily_capacity'))
                    ->helperText('Maximum leads this agent can receive per business day.'),
                TextInput::make('assignment_weight')->numeric()->required()->minValue(1)->maxValue(10)->default(1)
                    ->helperText('Reserved for weighted Round Robin.'),
            ]),
        ]);
    }
}
