<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'This account has been deactivated.',
                'errors' => null,
            ], 403);
        }

        return $next($request);
    }
}
