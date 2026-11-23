<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Schemas;

use App\Enums\LeadPriority;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class LeadForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Lead')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(150),
                    TextInput::make('phone')->tel()->required()->maxLength(32)
                        ->regex('/^\+?[\d\s\-()]{6,32}$/'),
                    Select::make('source')->options(LeadSource::options())->required()->native(false),
                    Select::make('priority')->options(LeadPriority::options())
                        ->default(LeadPriority::Medium->value)->required()->native(false),
                    DateTimePicker::make('next_follow_up_at')->label('Next follow-up')->seconds(false)
                        ->helperText('Defaults to 30 minutes after creation.'),
                    // Status is set to "New" on creation; changes after that are audited.
                    Select::make('status')->options(LeadStatus::options())->required()->native(false)
                        ->hiddenOn('create'),
                ]),
        ]);
    }
}
