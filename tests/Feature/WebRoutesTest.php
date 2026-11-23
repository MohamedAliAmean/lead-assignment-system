<?php

declare(strict_types=1);

it('serves the home page with links to every interface', function (): void {
    $this->get('/')->assertOk()
        ->assertSee(url('/admin'), false)
        ->assertSee(url('/workspace'), false)
        ->assertSee(url('/api/documentation'), false);
});

it('serves the Angular workspace shell for any workspace route', function (string $path): void {
    $this->get($path)->assertOk()
        ->assertHeader('Cache-Control', 'no-cache, private')
        ->assertSee('<app-root>', false)
        ->assertSee('<base href="/workspace/">', false);
})->with(['/workspace', '/workspace/leads', '/workspace/login']);
