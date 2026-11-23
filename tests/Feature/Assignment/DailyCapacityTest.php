<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;

it('skips agents who reached their daily capacity', function (): void {
    $small = User::factory()->agent()->capacity(1)->create();
    $large = User::factory()->agent()->capacity(10)->create();

    $assignees = createLeads(5);

    expect(array_count_values($assignees))->toBe([$small->id => 1, $large->id => 4]);
});

it('leaves leads unassigned when every agent is at capacity', function (): void {
    User::factory()->agent()->capacity(2)->count(2)->create();

    $assignees = createLeads(6);

    expect(array_filter($assignees))->toHaveCount(4)
        ->and(array_slice($assignees, 4))->toBe([null, null]);
});

it('gives capacity back on the next business day', function (): void {
    config(['leads.business_timezone' => 'Africa/Cairo']);
    $this->travelTo(CarbonImmutable::parse('2026-11-23 21:00', 'Africa/Cairo'));
    $agent = User::factory()->agent()->capacity(1)->create();

    expect(createLeads(2))->toBe([$agent->id, null]);

    // Midnight in Cairo, not UTC, starts the new day.
    $this->travelTo(CarbonImmutable::parse('2026-11-24 00:05', 'Africa/Cairo'));

    expect(createLeads(1))->toBe([$agent->id]);
});

it('applies a capacity change immediately', function (): void {
    $agent = User::factory()->agent()->capacity(1)->create();
    createLeads(1);

    $agent->update(['daily_capacity' => 3]);

    expect(createLeads(3))->toBe([$agent->id, $agent->id, null]);
});
