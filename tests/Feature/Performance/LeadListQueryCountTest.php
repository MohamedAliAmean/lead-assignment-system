<?php

declare(strict_types=1);

use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * R8.1 / R9.9: the number of queries to render the lead list must not grow
 * with the number of rows (no N+1). Lazy loading is also disabled in tests,
 * so any accidental N+1 would throw.
 */
function queriesToRenderLeadList(): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(ListLeads::class)->assertSuccessful();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('renders the lead list with a constant number of queries', function (): void {
    $admin = User::factory()->admin()->create();
    $agents = User::factory()->agent()->count(5)->create();
    $this->actingAs($admin);

    foreach ($agents as $agent) {
        Lead::factory()->count(1)->assignedTo($agent)->create();
    }
    $withFewRows = queriesToRenderLeadList();

    foreach ($agents as $agent) {
        Lead::factory()->count(9)->assignedTo($agent)->create();
    }
    $withManyRows = queriesToRenderLeadList();

    expect($withManyRows)->toBe($withFewRows)
        ->and($withManyRows)->toBeLessThanOrEqual(8);
});
