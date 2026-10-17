<?php

namespace App\Http\Controllers\OAuth;

use App\Models\OAuthClient;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

// GET /oauth/authorize. Passport's controller, except consent is ALWAYS shown. Passport skips it when
// the user already holds a token for the client with the requested scopes, but MCP clients only ever
// request `mcp:use`, so a second connection would silently reuse the first family + module choices.
// Every connection must pick its family and permissions, so we never consider scopes "already granted".
// (First-party board clients, and the platform-admin client, skip consent through
// OAuthClient::skipsAuthorization instead.)
class AuthorizeController extends AuthorizationController
{
    public function authorize(
        ServerRequestInterface $psrRequest,
        Request $request,
        ResponseInterface $psrResponse,
        AuthorizationViewResponse $viewResponse
    ): Response|AuthorizationViewResponse {
        // The board shows who asked for what, so board sign-in needs a verified email. Verification is
        // not enforced app-wide, so only board clients get this detour: send the email once per session,
        // show the verify page, and come back here afterwards (VerifyEmailController -> intended URL).
        $user = $this->guard->user();
        $isBoardClient = OAuthClient::isBoardClient($request->query('client_id'));
        if ($user && ! $user->hasVerifiedEmail() && $isBoardClient) {
            $request->session()->put('url.intended', $request->fullUrl());
            if (! $request->session()->get('board_verify_sent')) {
                $user->sendEmailVerificationNotification();
                $request->session()->put('board_verify_sent', true);
            }

            return redirect()->route('verification.notice');
        }

        // A board client may only ever ask for board:identity; the admin client (also platform_admin) for
        // exactly board:identity + admin. Anything else would render the MCP permissions screen, so refuse
        // before the parent runs (no view, nothing put in the session).
        $isAdminClient = $isBoardClient && OAuthClient::isPlatformAdminClient($request->query('client_id'));
        $scope = is_string($scope = $request->query('scope')) ? trim($scope) : '';
        if ($isBoardClient && $scope !== ($isAdminClient ? OAuthClient::ADMIN_SCOPE : 'board:identity')) {
            abort(403, 'This sign-in can only ask for onsplan board access.');
        }

        // Only platform admins get an admin.onsplan.nl code. A 403 (not a redirect back to the client)
        // can't be turned into an open redirect, and non-admins have no reason to be here.
        if ($isAdminClient && $user && ! $user->is_platform_admin) {
            abort(403, 'This onsplan account isn’t a platform admin.');
        }

        return parent::authorize($psrRequest, $request, $psrResponse, $viewResponse);
    }

    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        return false;
    }
}
