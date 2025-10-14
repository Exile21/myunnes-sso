<?php

declare(strict_types=1);

namespace MyUnnes\SSOClient\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use MyUnnes\SSOClient\Facades\SSOClient;
use RuntimeException;

/**
 * Drop-in controller that handles the full SSO auth lifecycle.
 */
class SSOController extends Controller
{
    /**
     * Redirect the browser to the SSO authorization screen.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $options = [];

        if ($prompt = $request->get('prompt')) {
            $options['prompt'] = $prompt;
        }

        if ($loginHint = $request->get('login_hint')) {
            $options['login_hint'] = $loginHint;
        }

        return SSOClient::redirect($options);
    }

    /**
     * Handle the callback from the SSO server.
     */
    public function callback(Request $request): RedirectResponse
    {
        try {
            $user = SSOClient::handleCallback($request);

            Auth::login($user, true);
            $request->session()->regenerate();

            $redirect = $request->session()->pull('url.intended')
                ?? config('sso-client.routes.redirect_after_login', '/dashboard');

            return redirect()->to($redirect);

        } catch (\Throwable $e) {
            Log::warning('SSO login failed', [
                'error' => $e->getMessage(),
            ]);

            SSOClient::logout();
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $fallbackRoute = config('sso-client.routes.fallback_login_route', 'login');
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'Authentication failed.';

            return redirect()->route($fallbackRoute)->withErrors([
                'sso' => $message,
            ]);
        }
    }

    /**
     * Destroy the local session and redirect through the SSO logout endpoint.
     */
    public function logout(Request $request): RedirectResponse
    {
        SSOClient::logout();
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $postLogout = $request->get('redirect', config('sso-client.routes.redirect_after_logout', '/'));

        return redirect()->away(SSOClient::getLogoutUrl(url($postLogout)));
    }
}
