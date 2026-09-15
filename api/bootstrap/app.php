<?php

use App\Exceptions\ImportProcessingException;
use App\Exceptions\ImportUploadException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Expected client errors (wrong type, too large, invalid CSV) are
        // not log-worthy, same as Laravel treats ValidationException.
        $exceptions->dontReport(ImportUploadException::class);

        $exceptions->render(
            fn (ImportUploadException $e) => response()->json([
                'message' => $e->getMessage(),
            ], $e->status())
        );

        // Processing failures are server-side problems (stream or insert
        // I/O), so unlike upload errors they stay reportable and map
        // to 500.
        $exceptions->render(
            fn (ImportProcessingException $e) => response()->json([
                'message' => $e->getMessage(),
            ], $e->status())
        );
    })->create();
