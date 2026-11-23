<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route as Router;

/*
| R11.8: the Swagger docs cannot silently drift from the code. Every /api/v1
| route must be documented with the same method and path.
*/

it('documents every api/v1 route in the OpenAPI spec', function (): void {
    Artisan::call('l5-swagger:generate');
    $spec = json_decode((string) file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);

    $documented = [];
    foreach ($spec['paths'] as $path => $operations) {
        foreach (array_keys($operations) as $method) {
            $documented[] = strtoupper($method).' '.$path;
        }
    }

    $routes = collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/'))
        ->flatMap(fn (Route $route): array => array_map(
            fn (string $method): string => $method.' /'.$route->uri(),
            array_diff($route->methods(), ['HEAD']),
        ))
        ->values()
        ->all();

    expect($routes)->not->toBeEmpty();
    expect(array_diff($routes, $documented))->toBe([], 'Undocumented API routes');
});

it('serves the Swagger UI', function (): void {
    $this->get('/api/documentation')->assertOk()->assertSee('swagger-ui', false);
});
