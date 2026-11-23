<?php

declare(strict_types=1);

use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Models\Lead;
use App\Models\User;
use Livewire\Livewire;

/*
| Runs with committed data: InnoDB only adds rows to a FULLTEXT index on
| commit, so this cannot run inside a rolled-back test transaction.
*/

it('searches names with the FULLTEXT index and phones by prefix', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $alice = Lead::factory()->create(['name' => 'Alice Johnson', 'phone' => '+201001112222']);
    $bob = Lead::factory()->create(['name' => 'Bob Stone', 'phone' => '+201557778888']);

    Livewire::test(ListLeads::class)->searchTable('johnson')
        ->assertCanSeeTableRecords([$alice])->assertCanNotSeeTableRecords([$bob]);

    Livewire::test(ListLeads::class)->searchTable('Joh')
        ->assertCanSeeTableRecords([$alice])->assertCanNotSeeTableRecords([$bob]);

    Livewire::test(ListLeads::class)->searchTable('+20155')
        ->assertCanSeeTableRecords([$bob])->assertCanNotSeeTableRecords([$alice]);
});

it('never uses a leading wildcard that would scan the whole table', function (): void {
    $sql = Lead::query()->search('Alice')->toSql().' | '.Lead::query()->search('0100')->toSql();

    expect($sql)->toContain('match (`leads`.`name`) against')
        ->and($sql)->not->toContain("'%");
});
