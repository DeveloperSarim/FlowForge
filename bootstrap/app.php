<?php

use App\Domain\Installer\EnsureInstalled;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// Fresh upload with no .env yet: create one with a random APP_KEY so the web installer can boot (sessions + CSRF need a key).
$basePath = dirname(__DIR__);
if (! is_file($basePath.'/.env') && is_file($basePath.'/.env.example')) {
    @file_put_contents($basePath.'/.env', preg_replace(
        '/^APP_KEY=.*$/m',
        'APP_KEY=base64:'.base64_encode(random_bytes(32)),
        file_get_contents($basePath.'/.env.example'),
    ));
}

return Application::configure(basePath: $basePath)
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [EnsureInstalled::class], append: [HandleInertiaRequests::class]);
        $middleware->api(prepend: [EnsureInstalled::class]);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
