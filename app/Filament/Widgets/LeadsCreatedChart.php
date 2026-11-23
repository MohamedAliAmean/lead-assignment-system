<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\User;
use App\Queries\LeadDashboardStats;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

final class LeadsCreatedChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'New leads, last 14 days';

    protected ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 2];

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $series = app(LeadDashboardStats::class)->createdPerDay($user, 14);

        return [
            'datasets' => [[
                'label' => 'Leads',
                'data' => array_values($series),
                'fill' => true,
                'tension' => 0.35,
            ]],
            'labels' => array_map(static fn (string $day): string => CarbonImmutable::parse($day)->format('M j'), array_keys($series)),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }
}
