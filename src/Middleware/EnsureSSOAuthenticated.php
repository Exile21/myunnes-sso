<?php

declare(strict_types=1);

namespace MyUnnes\SSOClient\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use MyUnnes\SSOClient\Facades\SSOClient;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensure SSO Authentication Middleware
 *
 * Redirects unauthenticated users to SSO login.
 */
class EnsureSSOAuthenticated
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string|null $guard
     * @return Response
     */
    public function handle(Request $request, Closure $next, ?string $guard = null): Response
    {
        // Check if user is authenticated via standard Laravel auth
        if (Auth::guard($guard)->check()) {
            return $next($request);
        }

        // Check if user has valid SSO session
        if (SSOClient::isAuthenticated()) {
            try {
                // Try to get user info and authenticate
                $userInfo = SSOClient::getUserInfo();
                $user = SSOClient::findUserBySSOId($userInfo['sub'] ?? '');

                if ($user) {
                    Auth::guard($guard)->login($user);
                    return $next($request);
                }
            } catch (\Exception $e) {
                // SSO token might be expired, clear it
                SSOClient::logout();
            }
        }

        // Store intended URL for post-authentication redirect
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        session(['url.intended' => $request->url()]);

        // Redirect to SSO login
        return SSOClient::redirect();
    }
}
