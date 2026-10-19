<?php

namespace App\Http\Middleware;

use App\Http\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the acting user from the X-Demo-User header (DD6). A role switch, not authentication.
 */
class ResolveDemoUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('X-Demo-User');

        // Only a plain positive id reaches the query; anything else would be a database error, not a 401.
        $user = is_string($header) && preg_match('/^[1-9][0-9]{0,17}$/', $header)
            ? User::find((int) $header)
            : null;

        if ($user === null) {
            throw new ApiException(401, 'unknown_user', 'Send the id of a seeded user in the X-Demo-User header.');
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
