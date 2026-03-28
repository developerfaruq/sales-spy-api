<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {

        $response = $next($request);

        // Prevents browsers from MIME-sniffing a response
        // away from the declared content type
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Prevents your API from being embedded in an iframe
        // Protects against clickjacking attacks
        $response->headers->set('X-Frame-Options', 'DENY');

        // Tells the browser to activate its XSS filter
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // Controls how much referrer information is included
        // with requests — keeps your API URL from leaking
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Removes the header that tells attackers you are
        // running on Laravel/PHP
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        // Only on production — forces browsers to use HTTPS
        // for the next year even if they try HTTP
        if (app()->environment('production')) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        return $response;
    }
}
