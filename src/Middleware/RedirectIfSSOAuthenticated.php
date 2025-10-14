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
 * Redirect If SSO Authenticated Middleware
 *
 * Redirects authenticated users away from guest-only pages.
 */
class RedirectIfSSOAuthenticated
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string|null $redirectTo
     * @param string ...$guards
     * @return Response
     */
    public function handle(Request $request, Closure $next, ?string $redirectTo = null, string ...$guards): Response
    {
        $guards = empty($guards) ? [null] : $guards;

        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {
                return redirect($redirectTo ?? $this->getDefaultRedirectPath());
            }
        }

        // Check SSO authentication
        if (SSOClient::isAuthenticated()) {
            try {
                // Try to get user info and authenticate if not already
                if (!Auth::check()) {
                    $userInfo = SSOClient::getUserInfo();
                    $user = SSOClient::findUserBySSOId($userInfo['sub'] ?? '');

                    if ($user) {
                        Auth::login($user);
                    }
                }

                if (Auth::check()) {
                    return redirect($redirectTo ?? $this->getDefaultRedirectPath());
                }
            } catch (\Exception $e) {
                // SSO token might be expired, clear it
                SSOClient::logout();
            }
        }

        return $next($request);
    }

    /**
     * Get the default redirect path for authenticated users.
     */
    protected function getDefaultRedirectPath(): string
    {
        // Try common dashboard routes
        $routes = ['/dashboard', '/home', '/app'];

        foreach ($routes as $route) {
            if (\Illuminate\Support\Facades\Route::has(ltrim($route, '/'))) {
                return $route;
            }
        }

        return '/';
    }
}
