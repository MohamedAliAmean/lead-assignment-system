<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\RelationManagers;

use App\Enums\LeadStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only status history (R4.6). The audit log is append-only, so there are
 * no create/edit/delete actions.
 */
final class StatusLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'statusLogs';

    protected static ?string $title = 'Status history';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user:id,name'))
            ->paginated([10, 25])
            ->columns([
                TextColumn::make('changed_at')->label('When')->dateTime('Y-m-d H:i:s'),
                TextColumn::make('old_status')->label('From')->badge()->placeholder('— (created)')
                    ->formatStateUsing(fn (?LeadStatus $state): string => $state?->label() ?? '—'),
                TextColumn::make('new_status')->label('To')->badge()
                    ->formatStateUsing(fn (LeadStatus $state): string => $state->label())
                    ->color(fn (LeadStatus $state): string => $state->color()),
                TextColumn::make('user.name')->label('By')->placeholder('System'),
            ]);
    }
}
