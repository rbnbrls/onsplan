<?php

namespace App\Http\Controllers\OAuth;

use App\Models\OAuthClient;
use App\Support\Analytics;
use App\Support\Mcp\McpTools;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Http\Controllers\ConvertsPsrResponses;
use Laravel\Passport\Http\Controllers\HandlesOAuthErrors;
use Laravel\Passport\Http\Controllers\RetrievesAuthRequestFromSession;
use League\OAuth2\Server\AuthorizationServer;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

// POST /oauth/authorize. Same as Passport's ApproveAuthorizationController, except the scopes come from
// the consent form instead of the client's request: one family (`family:<id>`) plus `<module>:read` /
// `<module>:write` per module the person allowed. The client only ever asks for `mcp:use`; what the
// token can do is decided here. Validation runs BEFORE the auth request is pulled from the session, so
// a rejected form redirects back to a consent page that still works.
class ApproveController
{
    use ConvertsPsrResponses, HandlesOAuthErrors, RetrievesAuthRequestFromSession;

    public function __construct(protected AuthorizationServer $server) {}

    public function approve(Request $request, ResponseInterface $psrResponse, ModuleRegistry $registry): Response
    {
        $user = $request->user();
        $data = $request->validate([
            'family_id' => ['required', 'integer', Rule::in($user->families()->pluck('families.id')->all())],
            'access' => ['nullable', 'array'],
            'access.*' => ['in:none,read,write'],
        ]);

        $familyId = (int) $data['family_id'];
        $access = $data['access'] ?? [];
        $allowed = array_intersect(array_keys(McpTools::modules()), $registry->enabledIds($familyId));
        if ($unknown = array_diff(array_keys($access), $allowed)) {
            throw ValidationException::withMessages(['access' => 'Not available in this family: '.implode(', ', $unknown)]);
        }

        $authRequest = $this->getAuthRequestFromSession($request);
        // Defence in depth: AuthorizeController never renders consent for a board client, but if a stale
        // or forged request for one reaches this POST it must not be given MCP scopes.
        abort_if(OAuthClient::isBoardClient($authRequest->getClient()->getIdentifier()), 403, 'This sign-in can only ask for onsplan board access.');
        $authRequest->setScopes(array_map(fn (string $s) => new Scope($s), McpTools::scopesFor($familyId, $access)));
        $authRequest->setAuthorizationApproved(true);

        $response = $this->withErrorHandling(fn () => $this->convertResponse(
            $this->server->completeAuthorizationRequest($authRequest, $psrResponse)
        ), false);
        // Only how many modules they shared, never which client or family.
        Analytics::track($user, 'assistant_connected', ['modules' => count(array_diff($access, ['none']))], '/oauth');

        return $response;
    }
}
