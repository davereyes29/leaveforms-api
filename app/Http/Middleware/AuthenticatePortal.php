<?php

namespace App\Http\Middleware;

use App\Services\Auth\PortalEmployeeResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads the portal cookie and attaches the logged-in employee to the request.
 *
 * This API does not issue its own sessions — it trusts the KDT portal cookie.
 */
class AuthenticatePortal
{
    public function __construct(
        private readonly PortalEmployeeResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $employee = $this->resolver->resolveFromCookie(
            $request->cookie(config('leave.portal_cookie_name'))
        );

        if ($employee === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'login_url' => config('leave.portal_login_url'),
            ], 401);
        }

        $request->attributes->set('employee', $employee);

        return $next($request);
    }
}
