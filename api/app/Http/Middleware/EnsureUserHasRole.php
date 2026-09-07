<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Coarse role gate for whole route groups (for example: the admin-only user
 * management endpoints).
 *
 * Per-record ownership rules still live in Policies -- this only answers "is
 * this actor even the right kind of actor for this area of the API?", which
 * keeps policies from having to re-check the role on every method.
 */
final class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error('Unauthenticated.', Response::HTTP_UNAUTHORIZED);
        }

        if (! in_array($user->role->value, $roles, strict: true)) {
            return ApiResponse::error(
                'Your account role does not grant access to this resource.',
                Response::HTTP_FORBIDDEN
            );
        }

        return $next($request);
    }
}
