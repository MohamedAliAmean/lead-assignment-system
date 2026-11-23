<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\LeadStatus;
use App\Models\User;
use App\Queries\LeadDashboardStats;
use Filament\Widgets\ChartWidget;

final class LeadStatusChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Pipeline by status';

    protected ?string $maxHeight = '260px';

    private const array COLORS = [
        'new' => '#3B82F6',
        'contacted' => '#F59E0B',
        'qualified' => '#6366F1',
        'unqualified' => '#9CA3AF',
        'won' => '#10B981',
        'lost' => '#EF4444',
    ];

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $breakdown = app(LeadDashboardStats::class)->statusBreakdown($user);

        return [
            'datasets' => [[
                'data' => array_values($breakdown),
                'backgroundColor' => array_map(static fn (string $status): string => self::COLORS[$status], array_keys($breakdown)),
                'borderWidth' => 0,
            ]],
            'labels' => array_map(static fn (string $status): string => LeadStatus::from($status)->label(), array_keys($breakdown)),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'cutout' => '65%',
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => ['x' => ['display' => false], 'y' => ['display' => false]],
        ];
    }
}
