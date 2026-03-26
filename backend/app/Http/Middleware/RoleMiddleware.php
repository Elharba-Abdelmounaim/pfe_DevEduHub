<?php

namespace App\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Usage: ->middleware('role:teacher')
     *         ->middleware('role:teacher,student')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, $roles)) {
            return response()->json([
                'message' => 'Forbidden. Required role: ' . implode(' or ', $roles),
            ], 403);
        }

        return $next($request);
    }
}
