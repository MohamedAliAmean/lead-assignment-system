<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Tables;

use App\Actions\Leads\BulkAssignLeads;
use App\Actions\Leads\ChangeLeadStatus;
use App\Authorization\AssigneeEligibility;
use App\Data\BulkAssignData;
use App\Enums\LeadPriority;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Filament\Support\DomainErrorNotifier;
use App\Models\Builders\LeadBuilder;
use App\Models\Lead;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Lead list (R7). Performance notes (R8):
 *  - only the displayed columns are selected and only assignedUser(id, name) is
 *    eager loaded, so the query count is constant per page;
 *  - every filter is a LeadBuilder scope backed by an index;
 *  - "Time until follow-up" is computed in PHP from a loaded column;
 *  - the bulk action receives record keys only (no model hydration).
 */
final class LeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (LeadBuilder $query): LeadBuilder => $query
                ->select(['leads.id', 'leads.name', 'leads.phone', 'leads.source', 'leads.status', 'leads.priority',
                    'leads.assigned_to', 'leads.next_follow_up_at', 'leads.created_at'])
                ->with('assignedUser:id,name'))
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->columns(self::columns())
            ->filters(self::filters())
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                self::changeStatusAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::bulkAssignAction(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * @return list<TextColumn>
     */
    private static function columns(): array
    {
        return [
            TextColumn::make('name')
                ->weight('medium')
                // One search box, index-friendly: FULLTEXT on name, prefix on phone.
                ->searchable(query: fn (LeadBuilder $query, string $search): LeadBuilder => $query->search($search)),
            TextColumn::make('phone')->copyable(),
            TextColumn::make('source')
                ->formatStateUsing(fn (LeadSource $state): string => $state->label()),
            TextColumn::make('status')->badge()
                ->formatStateUsing(fn (LeadStatus $state): string => $state->label())
                ->color(fn (LeadStatus $state): string => $state->color()),
            TextColumn::make('priority')->badge()
                ->formatStateUsing(fn (LeadPriority $state): string => $state->label())
                ->color(fn (LeadPriority $state): string => $state->color())
                ->sortable(),
            TextColumn::make('assignedUser.name')->label('Assigned user')->placeholder('Unassigned'),
            TextColumn::make('next_follow_up_at')
                ->label('Time until follow-up')
                ->sortable()
                ->placeholder('—')
                ->formatStateUsing(fn (Lead $record): string => self::timeUntilFollowUp($record))
                ->color(fn (Lead $record): ?string => $record->isOverdue(now()->toImmutable()) ? 'danger' : null)
                ->tooltip(fn (Lead $record): ?string => $record->next_follow_up_at?->toDayDateTimeString()),
        ];
    }

    /**
     * @return list<Filter|SelectFilter>
     */
    private static function filters(): array
    {
        return [
            Filter::make('my_leads')->label('My Leads')->toggle()
                ->query(fn (LeadBuilder $query): LeadBuilder => $query->assignedTo(self::user())),
            // Managers and agents never see unassigned leads, so the filter is admin-only.
            Filter::make('unassigned')->label('Unassigned')->toggle()
                ->visible(fn (): bool => self::user()->isAdmin())
                ->query(fn (LeadBuilder $query): LeadBuilder => $query->unassigned()),
            Filter::make('overdue')->label('Overdue')->toggle()
                ->query(fn (LeadBuilder $query): LeadBuilder => $query->overdue(now())),
            Filter::make('high_priority')->label('High Priority')->toggle()
                ->query(fn (LeadBuilder $query): LeadBuilder => $query->highPriority()),
            SelectFilter::make('status')->options(LeadStatus::options()),
        ];
    }

    private static function changeStatusAction(): Action
    {
        return Action::make('changeStatus')
            ->label('Change status')
            ->icon(Heroicon::OutlinedArrowPath)
            ->authorize('changeStatus')
            ->schema([
                Select::make('status')->options(LeadStatus::options())->required()->native(false),
            ])
            ->fillForm(fn (Lead $record): array => ['status' => $record->status->value])
            ->action(function (Lead $record, array $data): void {
                try {
                    app(ChangeLeadStatus::class)->handle($record, LeadStatus::from($data['status']), self::user());
                    Notification::make()->success()->title('Status updated')->send();
                } catch (Throwable $e) {
                    DomainErrorNotifier::notify($e) ?: throw $e;
                }
            });
    }

    private static function bulkAssignAction(): BulkAction
    {
        return BulkAction::make('assign')
            ->label('Assign to user')
            ->icon(Heroicon::OutlinedUserPlus)
            ->visible(fn (): bool => self::user()->can('assign', Lead::class))
            ->fetchSelectedRecords(false) // keys only: no model hydration (R8.3)
            ->schema([
                Select::make('assignee_id')
                    ->label('Assign to')
                    ->required()
                    ->searchable()
                    ->options(fn (): array => app(AssigneeEligibility::class)
                        ->assignableAgentsWithRemaining(self::user(), app(BusinessClock::class)->today())
                        ->mapWithKeys(fn (array $agent): array => [
                            $agent['id'] => "{$agent['name']} ({$agent['remaining']} left today)",
                        ])
                        ->all()),
            ])
            ->action(function (Collection $records, array $data): void {
                try {
                    $moved = app(BulkAssignLeads::class)->handle(
                        new BulkAssignData($records, (int) $data['assignee_id']),
                        self::user(),
                    );
                    Notification::make()->success()->title("{$moved} lead(s) assigned")->send();
                } catch (Throwable $e) {
                    DomainErrorNotifier::notify($e, 'Nothing was assigned') ?: throw $e;
                }
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function timeUntilFollowUp(Lead $record): string
    {
        $at = $record->next_follow_up_at;

        if ($at === null) {
            return '—';
        }

        $diff = $at->diffForHumans(now(), ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2, 'short' => true]);

        return $record->isOverdue(now()->toImmutable()) ? "Overdue by {$diff}" : ($at->isPast() ? $diff.' ago' : "In {$diff}");
    }

    private static function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
