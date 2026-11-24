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

arch('dashboard widgets read numbers through the shared query class, not raw SQL')
    ->expect('App\Filament\Widgets')
    ->not->toUse(['Illuminate\Support\Facades\DB', 'Illuminate\Support\Facades\Cache']);

arch('HTTP controllers hold no persistence logic')
    ->expect('App\Http\Controllers')
    ->not->toUse(['Illuminate\Support\Facades\DB', 'Illuminate\Database\ConnectionInterface']);

arch('the domain never depends on delivery layers')
    ->expect(['App\Actions', 'App\Assignment', 'App\Authorization', 'App\Escalation', 'App\Data', 'App\Models', 'App\Jobs', 'App\Queries'])
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

/*
| Clean Architecture dependency rule (pragmatic variant, see TECHNICAL_NOTES §1):
| inner layers (use cases, domain services, DTOs) must not reach outwards to
| infrastructure or delivery, and must not locate services globally.
*/

arch('use cases never touch infrastructure or delivery directly')
    ->expect('App\Actions')
    ->not->toUse([
        'App\Jobs', 'App\Notifications', 'App\Listeners', 'App\Http', 'App\Filament',
        'Illuminate\Support\Facades', 'Illuminate\Support\Str',
    ]);

arch('domain services never touch infrastructure')
    ->expect(['App\Assignment', 'App\Authorization', 'App\Escalation', 'App\Queries'])
    ->not->toUse(['App\Jobs', 'App\Notifications', 'App\Listeners', 'App\Http', 'App\Filament', 'Illuminate\Support\Facades']);

/*
| Pest's arch() does not see calls to global helper functions inside namespaced
| classes, so this rule scans the PHP tokens itself.
*/
it('inner layers use constructor injection, never a service locator', function (): void {
    $forbidden = ['app', 'resolve', 'config'];
    $skipBefore = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW];
    $violations = [];

    foreach (['Actions', 'Assignment', 'Authorization', 'Escalation', 'Queries', 'Data', 'Models', 'Observers'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path($dir), FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $tokens = array_values(array_filter(
                token_get_all((string) file_get_contents($file->getPathname())),
                static fn ($t): bool => ! (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)),
            ));

            foreach ($tokens as $i => $token) {
                $isName = is_array($token) && in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true);
                $name = $isName ? strtolower(ltrim($token[1], '\\')) : null;
                $previous = $tokens[$i - 1] ?? null;

                if ($name !== null && in_array($name, $forbidden, true)
                    && ($tokens[$i + 1] ?? null) === '('
                    && ! (is_array($previous) && in_array($previous[0], $skipBefore, true))) {
                    $violations[] = str_replace(base_path().'/', '', $file->getPathname()).":{$token[2]} {$name}()";
                }
            }
        }
    }

    expect($violations)->toBe([]);
});

arch('DTOs are independent of persistence models')
    ->expect('App\Data')
    ->not->toUse(['App\Models', 'Illuminate\Database']);

arch('output ports are interfaces implemented outside the domain')
    ->expect('App\Notifications\DatabaseEscalationNotifier')
    ->toImplement('App\Escalation\Contracts\EscalationNotifier');

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

arch()->preset()->security()->ignoring('assert');
