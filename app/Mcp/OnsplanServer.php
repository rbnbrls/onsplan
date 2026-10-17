<?php

namespace App\Mcp;

use App\Mcp\Apps\Widget;
use App\Support\Mcp\McpTools;
use Laravel\Mcp\Schema\Icon;
use Laravel\Mcp\Server;

// The onsplan MCP server (POST /mcp). Serves every tool any module registered with McpTools; each
// tool's shouldRegister() hides it unless the token's scopes and the family's enabled modules allow it.
class OnsplanServer extends Server
{
    protected string $name = 'onsplan';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        onsplan is a family organiser: shared to-do lists, shopping lists, expenses and notes.
        This connection acts for one signed-in person inside ONE family, chosen when they connected.
        Everything you read or change belongs to that family and respects that person's sharing rules.
        Call get_family first to see the family, its members, and what this connection may read or change.
        Deletes are reversible for 30 days (see the *_list_deleted and *_restore tools).
        Every entity includes a `url` you can give the person to open it in onsplan.
        MARKDOWN;

    // tools/list pages at 15 by default; serve the whole (scope-filtered) set in one page.
    public int $defaultPaginationLength = 50;

    /**
     * The connector's icon in assistants (serverInfo.icons): the rounded app icon, so it reads as an app
     * tile next to the name instead of a hard square. Absolute app URLs (served from public/icons).
     *
     * @return list<Icon>
     */
    protected function icons(): array
    {
        $url = rtrim((string) config('app.url'), '/');

        return [
            new Icon("{$url}/icons/icon-rounded-512.png", 'image/png', ['512x512']),
            new Icon("{$url}/icons/icon-rounded-192.png", 'image/png', ['192x192']),
            new Icon("{$url}/icons/icon-rounded-64.png", 'image/png', ['64x64']),
        ];
    }

    protected function boot(): void
    {
        $this->tools = McpTools::all();
        // tools/call with an assistant_tool analytics event (App\Mcp\TrackedCallTool).
        $this->addMethod('tools/call', TrackedCallTool::class);
        // The MCP Apps widgets tools point at. Static HTML with no family data in it (the data arrives as the
        // tool result), so they are always listed; reading one needs only a valid token.
        $this->resources = array_map(fn (string $w) => new Widget($w), array_keys(Widget::WIDGETS));
    }
}
