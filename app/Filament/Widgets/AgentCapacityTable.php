<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\User;
use App\Queries\LeadDashboardStats;
use Filament\Widgets\Widget;

/**
 * Today's capacity usage per assignable agent (FA6). Admins see every agent,
 * managers their team; agents do not see this widget.
 */
final class AgentCapacityTable extends Widget
{
    protected static ?int $sort = 4;

    protected string $view = 'filament.widgets.agent-capacity';

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 1];

    public static function canView(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user !== null && ($user->isAdmin() || $user->isManager());
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return ['agents' => app(LeadDashboardStats::class)->capacityToday($user)];
    }
}
