<?php

namespace App\Http\Middleware;

use App\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('role:supervisor'). Runs after ResolveDemoUser.
 */
class RequireRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        if ($request->user()?->role !== $role) {
            throw new ApiException(403, 'forbidden_role', "This action is for the {$role} role.", ['required_role' => $role]);
        }

        return $next($request);
    }
}
