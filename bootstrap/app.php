<?php

use App\Modules\Platform\Http\Middleware\EnforceCompanyAccess;
use App\Modules\Platform\Http\Middleware\TrackApiUsage;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : '/'
        );
        $middleware->appendToGroup('api', [
            EnforceCompanyAccess::class,
            TrackApiUsage::class,
            'throttle:erp-api',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (ValidationException $exception) {
            return ApiResponse::error(
                'Validation failed',
                $exception->errors(),
                $exception->status
            );
        });

        $exceptions->render(function (AuthenticationException $exception) {
            return ApiResponse::error($exception->getMessage(), status: 401);
        });

        $exceptions->render(function (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), status: 403);
        });
        $exceptions->renderable(function (Throwable $e) {
            if ($e instanceof UnauthorizedException) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                ], 403);
            }
            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }
            if ($e instanceof ModelNotFoundException) {
                return response()->json([
                    'message' => 'The resource you are looking for does not exist.',
                ], 404);
            }
            if ($e instanceof ValidationException) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $e->errors(),
                ], 422);
            }
            if ($e instanceof HttpResponseException) {
                return $e->getResponse();
            }
            if ($e instanceof HttpException) {
                return response()->json([
                    'message' => $e->getMessage(),
                ], $e->getStatusCode());
            }
            if ($e instanceof AuthorizationException) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                ], 403);
            }
            if ($e instanceof QueryException) {
                return response()->json([
                    'message' => 'A database error occurred.',
                ], 500);
            }
            if ($e instanceof ThrottleRequestsException) {
                return response()->json([
                    'message' => 'Too many attempts. Please try again later.',
                ], 429);
            }
        });
    })->create();
