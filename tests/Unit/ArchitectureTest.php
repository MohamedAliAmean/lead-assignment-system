<?php

declare(strict_types=1);

/*
| Architecture rules (constitution §3–§4), enforced on every test run.
*/

arch('every class uses strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('use cases are final single-method Actions')
    ->expect('App\Actions')
    ->toBeFinal()
    ->toHaveMethod('handle');

arch('DTOs are immutable')
    ->expect('App\Data')
    ->toBeFinal()
    ->toBeReadonly();

arch('the admin panel holds no persistence or business logic')
    ->expect('App\Filament')
    ->not->toUse([
        'Illuminate\Support\Facades\DB',
        'Illuminate\Database\ConnectionInterface',
        'App\Assignment\CapacityLedger',
        'App\Assignment\LeadAssigner',
    ]);

arch('HTTP controllers hold no persistence logic')
    ->expect('App\Http\Controllers')
    ->not->toUse(['Illuminate\Support\Facades\DB', 'Illuminate\Database\ConnectionInterface']);

arch('the domain never depends on delivery layers')
    ->expect(['App\Actions', 'App\Assignment', 'App\Authorization', 'App\Escalation', 'App\Data', 'App\Models', 'App\Jobs'])
    ->not->toUse(['App\Filament', 'App\Http', 'Filament\Tables', 'Filament\Forms', 'Filament\Actions']);

arch('business services depend on contracts, not on concrete strategies')
    ->expect('App\Assignment\LeadAssigner')
    ->not->toUse('App\Assignment\RoundRobinStrategy');

arch('contracts are interfaces')
    ->expect(['App\Assignment\Contracts', 'App\Authorization\Contracts', 'App\Escalation\Contracts', 'App\Webhooks\Contracts'])
    ->toBeInterfaces();

arch('domain exceptions extend the domain base exception')
    ->expect('App\Exceptions\Domain')
    ->toExtend('App\Exceptions\Domain\DomainException')
    ->ignoring('App\Exceptions\Domain\DomainException');

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

arch()->preset()->security()->ignoring('assert');
