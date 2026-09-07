<?php

declare(strict_types=1);

use App\Exceptions\DomainException;
use App\Http\Middleware\ForceJsonResponse;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every request handled by this application is an API request, so the
        // client never has to remember to send an `Accept: application/json`
        // header for errors to come back as JSON.
        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // ------------------------------------------------------------------
        // Single, centralised place that converts every exception leaving the
        // application into the standard envelope described in the PRD:
        //   { "success": bool, "data": ..., "message": ..., "errors": ... }
        // Controllers therefore never need try/catch blocks for these cases.
        // ------------------------------------------------------------------
        $exceptions->shouldRenderJsonWhen(fn () => true);

        $exceptions->render(function (ValidationException $e) {
            return ApiResponse::validationError($e->errors());
        });

        $exceptions->render(function (AuthenticationException $e) {
            return ApiResponse::error('Unauthenticated.', Response::HTTP_UNAUTHORIZED);
        });

        $exceptions->render(function (AuthorizationException $e) {
            return ApiResponse::error(
                $e->getMessage() ?: 'This action is unauthorized.',
                Response::HTTP_FORBIDDEN
            );
        });

        $exceptions->render(function (ModelNotFoundException $e) {
            return ApiResponse::error('Resource not found.', Response::HTTP_NOT_FOUND);
        });

        $exceptions->render(function (NotFoundHttpException $e) {
            return ApiResponse::error('Resource not found.', Response::HTTP_NOT_FOUND);
        });

        $exceptions->render(function (TooManyRequestsHttpException $e) {
            return ApiResponse::error('Too many requests. Please slow down.', Response::HTTP_TOO_MANY_REQUESTS);
        });

        // Domain exceptions carry their own HTTP status and optional context
        // (e.g. which product ran out of stock, and how much was left).
        $exceptions->render(function (DomainException $e) {
            return ApiResponse::error($e->getMessage(), $e->status(), $e->context());
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            return ApiResponse::error(
                $e->getMessage() ?: 'Request could not be processed.',
                $e->getStatusCode()
            );
        });

        $exceptions->render(function (Throwable $e) {
            if (config('app.debug')) {
                return null; // Fall through to Laravel's verbose debug handler.
            }

            return ApiResponse::error('Server error.', Response::HTTP_INTERNAL_SERVER_ERROR);
        });
    })->create();
