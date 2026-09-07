<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * This application only speaks JSON.
 *
 * Without this, a client that forgets `Accept: application/json` gets an HTML
 * error page (or a redirect to a login route that does not exist) instead of
 * the documented error envelope. Rewriting the header at the edge means every
 * failure mode is machine-readable.
 */
final class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
