<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\Leads\LeadResource;
use App\Models\User;
use App\Queries\LeadDashboardStats;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Role-scoped KPI cards (frontend spec FA3). Numbers come from the shared
 * LeadDashboardStats query class, cached per visibility scope.
 */
final class LeadStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $summary = app(LeadDashboardStats::class)->summary($user);
        $scope = match (true) {
            $user->isAdmin() => 'All leads',
            $user->isManager() => 'Your team',
            default => 'Assigned to you',
        };

        $stats = [
            Stat::make('Open leads', number_format($summary['open']))
                ->description($scope)
                ->icon(Heroicon::OutlinedInboxStack)
                ->url(LeadResource::getUrl('index')),
            Stat::make('Overdue', number_format($summary['overdue']))
                ->description('Follow-up time has passed')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color($summary['overdue'] > 0 ? 'danger' : 'success')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->url(LeadResource::getUrl('index', ['filters' => ['overdue' => ['isActive' => true]]])),
            Stat::make('New today', number_format($summary['new_today']))
                ->description('Created since midnight')
                ->color('info')
                ->icon(Heroicon::OutlinedSparkles),
            Stat::make('Escalated today', number_format($summary['escalated_today']))
                ->description('Stayed "New" for 30+ minutes')
                ->color($summary['escalated_today'] > 0 ? 'warning' : 'gray')
                ->icon(Heroicon::OutlinedBellAlert),
        ];

        if ($summary['unassigned'] !== null) {
            $stats[] = Stat::make('Unassigned', number_format($summary['unassigned']))
                ->description('No eligible agent had capacity')
                ->color($summary['unassigned'] > 0 ? 'warning' : 'success')
                ->icon(Heroicon::OutlinedUserMinus)
                ->url(LeadResource::getUrl('index', ['filters' => ['unassigned' => ['isActive' => true]]]));
        }

        return $stats;
    }

    protected function getColumns(): int
    {
        return count($this->getCachedStats()) > 4 ? 5 : 4;
    }
}
