<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
// use Throwable;

use App\Enums\HTTPCodes;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // when to return json
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (Throwable $e, Request $request) {

            // For non-API requests, let Laravel handle it normally
            if (!$request->is('api/*')) {
                return null;
            }

            // Handle ValidationException
            if ($e instanceof ValidationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $e->errors(),
                    'code' => HTTPCodes::VALIDATION_ERROR->value,
                ], HTTPCodes::VALIDATION_ERROR->value);
            }

            // Handle AuthenticationException
            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                    'code' => HTTPCodes::UNAUTHENTICATED->value,
                ], HTTPCodes::UNAUTHENTICATED->value);
            }

            // Handle AuthorizationException
            if ($e instanceof AuthorizationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden',
                    'code' => HTTPCodes::UNAUTHORIZED->value,
                ], HTTPCodes::UNAUTHORIZED->value);
            }

            // Handle ModelNotFoundException
            if ($e instanceof ModelNotFoundException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resource not found',
                    'code' => HTTPCodes::NOT_FOUND->value,
                ], HTTPCodes::NOT_FOUND->value);
            }

            // Handle NotFoundHttpException
            if ($e instanceof NotFoundHttpException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Route not found',
                    'code' => HTTPCodes::NOT_FOUND->value,
                ], HTTPCodes::NOT_FOUND->value);
            }

            // Handle ThrottleRequestsException
            if ($e instanceof ThrottleRequestsException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many requests',
                    'code' => HTTPCodes::TOO_MANY_REQUESTS->value,
                ], HTTPCodes::TOO_MANY_REQUESTS->value);
            }

            // Handle HttpException
            if ($e instanceof HttpException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'HTTP error',
                    'code' => $e->getStatusCode(),
                ], $e->getStatusCode());
            }

            // Local environment - show details
            if (app()->environment('local')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'trace' => collect($e->getTrace())->take(5)->toArray(),
                    'code' => HTTPCodes::INTERNAL_SERVER_ERROR->value,
                ], HTTPCodes::INTERNAL_SERVER_ERROR->value);
            }

            // Production - log and show generic message
            report($e); // logging exceptions for production environment

            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
                'code' => HTTPCodes::INTERNAL_SERVER_ERROR->value,
            ], HTTPCodes::INTERNAL_SERVER_ERROR->value);

        });
    })->create();
