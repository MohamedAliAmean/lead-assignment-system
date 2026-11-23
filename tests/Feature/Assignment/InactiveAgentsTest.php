<?php

declare(strict_types=1);

use App\Assignment\CapacityLedger;
use App\Models\User;
use App\Support\BusinessClock;

it('never assigns leads to inactive agents', function (): void {
    $active = User::factory()->agent()->count(2)->create();
    $inactive = User::factory()->agent()->inactive()->create();

    $assignees = createLeads(10);

    expect($assignees)->not->toContain($inactive->id)
        ->and(array_unique($assignees))->toEqualCanonicalizing($active->modelKeys());
});

it('stops assigning to an agent as soon as they are deactivated', function (): void {
    [$a, $b] = User::factory()->agent()->count(2)->create();

    createLeads(2);
    $a->update(['is_active' => false]);

    expect(array_unique(createLeads(4)))->toBe([$b->id]);
});

it('rejects a capacity reservation for an inactive agent at the database level', function (): void {
    $agent = User::factory()->agent()->inactive()->create();

    $reserved = app(CapacityLedger::class)->tryReserve($agent->id, app(BusinessClock::class)->today());

    expect($reserved)->toBeFalse();
});
