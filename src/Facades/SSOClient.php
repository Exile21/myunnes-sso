<?php

declare(strict_types=1);

namespace MyUnnes\SSOClient\Facades;

use Illuminate\Support\Facades\Facade;
use MyUnnes\SSOClient\SSOClient as SSOClientService;

/**
 * SSO Client Facade
 *
 * @method static \Illuminate\Http\RedirectResponse redirect(array $options = [])
 * @method static \Illuminate\Contracts\Auth\Authenticatable handleCallback(\Illuminate\Http\Request $request)
 * @method static array getUserInfo()
 * @method static array refreshToken()
 * @method static bool isAuthenticated()
 * @method static void logout()
 * @method static string getLogoutUrl(?string $redirectUrl = null)
 * @method static array|null getTokens()
 * @method static string|null getAccessToken()
 * @method static string|null getRefreshToken()
 * @method static string|null getIdToken()
 * @method static array|null validateIdToken(string $idToken = null, bool $verifySignature = true)
 * @method static bool revokeTokens()
 * @method static \Illuminate\Contracts\Auth\Authenticatable|null findUserBySSOId(string $ssoId)
 * @method static \Illuminate\Contracts\Auth\Authenticatable|null findUserByIdentifier(string $identifier)
 * @method static \MyUnnes\SSOClient\Services\SSOAuthService getAuthService()
 * @method static \MyUnnes\SSOClient\Services\UserService getUserService()
 *
 * @see \MyUnnes\SSOClient\SSOClient
 */
class SSOClient extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'sso-client';
    }
}
