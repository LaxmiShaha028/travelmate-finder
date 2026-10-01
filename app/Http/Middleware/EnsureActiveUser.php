<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next)
    {
        abort_if($request->user()?->is_blocked, 403, 'Your account is blocked.');

        return $next($request);
    }
}
