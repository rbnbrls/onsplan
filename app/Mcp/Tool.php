<?php

namespace App\Mcp;

use App\Mcp\Apps\Widget;
use App\Models\User;
use App\Support\Mcp\McpTools;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool as BaseTool;

/**
 * Base for every onsplan MCP tool. A tool belongs to one module and needs either read or write
 * access to it. shouldRegister() is the ONE permission gate: laravel/mcp only lists and only calls tools
 * whose shouldRegister() is true for the current request (an unlisted name is "tool not found"), so a
 * tool's handle() never re-checks scopes. It does still apply the per-entity sharing rules (a private
 * list of another member), because those are about the row, not the token.
 *
 * Tools run as the token's user inside CurrentFamily = the token's family (McpFamilyContext), so
 * BelongsToFamily scopes and module queries behave exactly as in the web UI.
 */
abstract class Tool extends BaseTool
{
    /** Module id this tool belongs to ('tasks', ...), or McpTools::CORE for the always-on tools. */
    protected string $module;

    /** 'read' | 'write' — which of the module's scopes the token must carry. */
    protected string $access = 'read';

    /**
     * MCP Apps widget that renders this tool's result (a Widget::WIDGETS key), or null for text only.
     * Hosts without MCP Apps support ignore it and show the JSON text as before.
     */
    protected ?string $app = null;

    protected const DEFAULT_LIMIT = 50;

    protected const MAX_LIMIT = 100;

    public function shouldRegister(): bool
    {
        $familyId = CurrentFamily::id();
        $user = auth()->user();
        if (! $familyId || ! $user instanceof User) {
            return false;
        }
        if ($this->module === McpTools::CORE) {
            return true;
        }

        return app(ModuleRegistry::class)->isEnabled($this->module, $familyId)
            && $user->tokenCan("{$this->module}:{$this->access}");
    }

    public function toArray(): array
    {
        if ($this->app !== null) {
            $this->setMeta('ui', ['resourceUri' => Widget::uriFor($this->app)]);
        }

        return parent::toArray();
    }

    /**
     * Every tool states title, readOnlyHint, destructiveHint and openWorldHint explicitly, read-only tools
     * included: the Claude directory and the ChatGPT app review both flag tools that leave any of them out
     * (ChatGPT rejected read-only tools without destructiveHint, even though the spec ignores it there),
     * and MCP's unset defaults are the worst case (readOnly false, destructive TRUE, openWorld TRUE).
     * Attributes on a tool still win:
     * - #[IsDestructive]: per the spec, anything that is not "only additive", so deletes AND updates that
     *   overwrite/clear fields, replace a file, unlink or move. Plain create/add/log/restore stay false.
     * - #[IsOpenWorld]: only the tools that fetch an arbitrary public `file_url`; everything else only
     *   touches this family's own data (TypeSafe guesses are internal enrichment, not the open world).
     */
    public function annotations(): array
    {
        return parent::annotations()
            + ['title' => $this->title(), 'readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false];
    }

    protected function user(): User
    {
        return auth()->user();
    }

    /** @return list<string> the scopes on the token this request carries. */
    protected function tokenScopes(): array
    {
        return (array) ($this->user()->token()?->oauth_scopes ?? []);
    }

    /** Absolute web URL for an app path, so the assistant can link the user to the entity. */
    protected function url(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    /** Shared schema fragment for paginated list tools. */
    protected function pageSchema($schema): array
    {
        return [
            'limit' => $schema->integer()->description('Max results to return (default '.self::DEFAULT_LIMIT.', max '.self::MAX_LIMIT.').'),
            'cursor' => $schema->string()->description('Opaque cursor from a previous call\'s next_cursor, to fetch the next page.'),
        ];
    }

    /**
     * One page of a query or an already-filtered collection, with an offset cursor.
     * ponytail: offset cursor, fine for family-sized data; switch to keyset paging if a list ever gets huge.
     *
     * @return array{items: Collection, next_cursor: ?string}
     */
    protected function page(Builder|Collection $source, Request $request): array
    {
        $limit = max(1, min(self::MAX_LIMIT, (int) ($request->get('limit') ?: self::DEFAULT_LIMIT)));
        $offset = max(0, (int) $request->get('cursor', 0));
        $rows = $source instanceof Builder
            ? $source->offset($offset)->limit($limit + 1)->get()
            : $source->slice($offset, $limit + 1)->values();

        return [
            'items' => $rows->take($limit),
            'next_cursor' => $rows->count() > $limit ? (string) ($offset + $limit) : null,
        ];
    }

    protected function notFound(string $what): Response
    {
        return Response::error("{$what} not found, or you don't have access to it.");
    }
}
