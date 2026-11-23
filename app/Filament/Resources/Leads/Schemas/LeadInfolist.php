<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Schemas;

use App\Enums\LeadPriority;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class LeadInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Lead')
                ->columns(3)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('phone')->copyable(),
                    TextEntry::make('source')->formatStateUsing(fn (LeadSource $state): string => $state->label()),
                    TextEntry::make('status')->badge()
                        ->formatStateUsing(fn (LeadStatus $state): string => $state->label())
                        ->color(fn (LeadStatus $state): string => $state->color()),
                    TextEntry::make('priority')->badge()
                        ->formatStateUsing(fn (LeadPriority $state): string => $state->label())
                        ->color(fn (LeadPriority $state): string => $state->color()),
                    TextEntry::make('assignedUser.name')->label('Assigned to')->placeholder('Unassigned'),
                    TextEntry::make('assigned_at')->dateTime()->placeholder('—'),
                    TextEntry::make('next_follow_up_at')->label('Next follow-up')->dateTime()->since()->placeholder('—'),
                    TextEntry::make('escalation.escalated_at')->label('Escalated')->dateTime()->placeholder('Not escalated'),
                    TextEntry::make('external_ref')->label('External reference')->placeholder('—'),
                    TextEntry::make('created_at')->dateTime(),
                ]),
        ]);
    }
}
