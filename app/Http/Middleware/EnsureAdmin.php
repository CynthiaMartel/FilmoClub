<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user()?->isAdmin()) {
            return response()->json(['success' => 0, 'message' => 'Acceso restringido a administradores.'], 403);
        }

        return $next($request);
    }
}
