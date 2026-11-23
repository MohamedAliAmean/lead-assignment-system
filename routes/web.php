<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

/*
| Agent Workspace: the Angular SPA (frontend spec FB1).
| - URLs /workspace/* always reach Laravel, which returns the SPA shell; the
|   Angular router takes it from there. Same origin as the API: no CORS.
| - Built files live in public/workspace-assets (Angular `deployUrl`). Keeping
|   the URL path and the directory name different means no web server (incl.
|   `php artisan serve`) ever resolves /workspace to a directory.
*/
Route::get('/workspace/{path?}', static function () {
    $shell = public_path('workspace-assets/app.html');
    abort_unless(is_file($shell), 404, 'The workspace has not been built. Run: cd frontend && npm ci && npm run build');

    // The shell is ~1 KB. Hashed JS/CSS files are cached by the browser; the
    // shell itself must be revalidated so a new build is picked up at once.
    return response((string) file_get_contents($shell), 200, [
        'Content-Type' => 'text/html; charset=UTF-8',
        'Cache-Control' => 'no-cache',
    ]);
})->where('path', '.*')->name('workspace');
