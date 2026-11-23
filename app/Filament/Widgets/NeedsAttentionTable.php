<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * The 10 most overdue open leads the user can see (FA7). Not paginated, so
 * Filament runs no COUNT(*): a single indexed query with LIMIT 10.
 */
final class NeedsAttentionTable extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Needs attention: most overdue follow-ups';

    public function table(Table $table): Table
    {
        /** @var User $user */
        $user = auth()->user();

        return $table
            ->query(fn () => Lead::query()
                ->visibleTo($user)
                ->overdue(now())
                ->with('assignedUser:id,name')
                ->reorder('leads.next_follow_up_at')
                ->limit(10))
            ->paginated(false)
            ->emptyStateHeading('Nothing overdue')
            ->emptyStateDescription('Every open lead is within its follow-up time.')
            ->recordUrl(fn (Lead $record): string => LeadResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')->weight('medium'),
                TextColumn::make('phone'),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (LeadStatus $state): string => $state->label())
                    ->color(fn (LeadStatus $state): string => $state->color()),
                TextColumn::make('priority')->badge()
                    ->formatStateUsing(fn (LeadPriority $state): string => $state->label())
                    ->color(fn (LeadPriority $state): string => $state->color()),
                TextColumn::make('assignedUser.name')->label('Agent')->placeholder('Unassigned'),
                TextColumn::make('next_follow_up_at')->label('Overdue by')->since()->color('danger'),
            ]);
    }
}
