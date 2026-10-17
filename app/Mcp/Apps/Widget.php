<?php

namespace App\Mcp\Apps;

use App\Support\Tenancy\CurrentFamily;
use App\Support\Theming\ThemeTokens;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\AppResource;
use Laravel\Mcp\Server\Ui\AppMeta;
use Laravel\Mcp\Server\Ui\Csp;

/**
 * One MCP Apps widget (https://modelcontextprotocol.io/extensions/apps/overview): a self-contained HTML
 * page the host (Claude, ChatGPT, ...) renders in a sandboxed iframe next to a tool's result. A tool opts
 * in with `protected ?string $app = '<widget>'` (App\Mcp\Tool adds the `_meta.ui.resourceUri`).
 *
 * Every widget is the shared runtime (resources/mcp-apps/core.{css,js}) plus its own resources/mcp-apps/
 * <widget>.js, all inlined: no network, so the CSP stays at the secure default (no external domains).
 * The widget renders the tool result it is pushed and changes things only by calling our existing write
 * tools through the host, so scopes, sharing rules, notifications and realtime are exactly the MCP ones.
 *
 * ponytail: one class instantiated per widget (OnsplanServer::boot) instead of a class per widget;
 * each instance is a plain listed resource, which every host handles (templates are less supported).
 */
class Widget extends AppResource
{
    /** Widget name => what it shows (resource description). Adding a widget = a line here + its js file. */
    public const WIDGETS = [
        'tasks' => 'Checklist for a to-do list or task search: tick tasks off, add tasks.',
        'shopping' => 'Shopping list grouped by aisle: tick items as bought, add items.',
        'project' => 'Project board: progress, tickable tasks, dependencies, linked shopping lists.',
        'today' => 'What is due today and this week, tickable.',
        'spending' => 'Spending summary chart by category/shop, or a list of expenses.',
        'inventory' => 'Household stock with +/- steppers and add-to-shopping.',
        'vehicle' => 'Car cards: odometer, maintenance (mark done), documents, history.',
        'birthdays' => 'Upcoming birthdays with countdowns.',
        'calendar' => 'Family calendar agenda grouped by day (multi-day trips as day N of M), or one event with its people and to-dos.',
        'note' => 'A note rendered with tickable checklists.',
        'contact' => 'A contact card with tap-to-call / email links.',
        'recipe' => 'Recipes: photo, live calculator (servings / total / what you have), ingredients, steps, add to shopping.',
    ];

    /** Widgets that can go full screen (they show a full-screen button when the host offers it). */
    public const FULLSCREEN = ['shopping', 'recipe'];

    /**
     * Widgets that show images from onsplan (signed photo URLs). Only these get an img-src for our own
     * origin in their CSP; every other widget keeps the no-external-resources default.
     */
    public const IMAGES = ['recipe'];

    /** Extra shared scripts a widget inlines before its own file (paths under resources/). */
    private const LIBS = ['recipe' => ['js/lib/recipe-scale.js']];

    public function __construct(public readonly string $widget)
    {
        $this->uri = self::uriFor($widget);
        $this->name = "onsplan-{$widget}";
        $this->title = 'onsplan '.$widget;
        $this->description = self::WIDGETS[$widget];
    }

    /**
     * Widget URIs are permanent: hosts keep the tool list (and so these URIs) from when the person connected
     * and only re-read the template, fresh, on every render (seen in prod logs: ChatGPT asked for the old
     * URI after a rename and got "not found"). Change what a widget serves, never its URI.
     */
    public static function uriFor(string $widget): string
    {
        return "ui://onsplan/{$widget}";
    }

    public function handle(Request $request): Response
    {
        $read = fn (string $file) => file_get_contents(resource_path("mcp-apps/{$file}"));

        return Response::view('mcp-app', [
            'title' => $this->title,
            'sdk' => self::sdk(in_array($this->widget, self::FULLSCREEN, true) ? ['inline', 'fullscreen'] : []),
            // The person's onsplan skin, same resolution as the web app. The page is read with their
            // token (McpFamilyContext has set the family), so this is their colours, not a shared default.
            'themeCss' => ThemeTokens::css(ThemeTokens::resolve(auth()->user(), CurrentFamily::id())),
            'css' => $read('core.css'),
            'js' => $read('core.js')."\n".$this->libs()."\n".$read("{$this->widget}.js"),
        ])
            // ChatGPT enforces only its own key (snake_case), not the standard ui.csp, so state the same
            // policy there: nothing external, plus this app's origin for widgets that show photos.
            ->withMeta('openai/widgetCSP', ['connect_domains' => [], 'resource_domains' => $this->imageOrigins()]);
    }

    /**
     * Shared ES modules inlined as plain script: the widget page is one classic <script>, so the `export`
     * keywords are dropped (the declarations stay global). recipe-scale.js is written for exactly this.
     */
    private function libs(): string
    {
        return collect(self::LIBS[$this->widget] ?? [])
            ->map(fn (string $f) => preg_replace('/^export /m', '', file_get_contents(resource_path($f))))
            ->implode("\n");
    }

    /**
     * No ui.domain: it is host-specific (Claude validates {sha256(server url)}.claudemcpcontent.com, ChatGPT
     * wants a dedicated origin the app controls) and laravel/mcp fills in the Claude value for every host,
     * which made ChatGPT show raw JSON instead of the widget. Left out, each host serves the widget from its
     * own default sandbox. Claude only needs a fixed domain for apps running their own OAuth; ours act
     * through host tool calls.
     */
    public function resolvedAppMeta(): array
    {
        $meta = parent::resolvedAppMeta();
        unset($meta['domain']);

        return $meta;
    }

    public function appMeta(): AppMeta
    {
        $origins = $this->imageOrigins();

        return $origins === [] ? parent::appMeta() : new AppMeta(csp: new Csp(resourceDomains: $origins));
    }

    /**
     * Photos are served by this app (signed URLs built on the request's host, mcp.onsplan.nl for MCP
     * calls): allow both that host and the main app URL, nothing else. Empty for widgets without images.
     *
     * @return list<string>
     */
    private function imageOrigins(): array
    {
        if (! in_array($this->widget, self::IMAGES, true)) {
            return [];
        }

        return collect([url('/'), config('app.url')])->map(fn ($u) => rtrim((string) $u, '/'))->unique()->values()->all();
    }

    /**
     * laravel/mcp's postMessage SDK with our display modes declared. The spec requires a view to list the
     * modes it supports in ui/initialize's appCapabilities.availableDisplayModes (hosts may refuse a
     * fullscreen request otherwise), but the SDK hardcodes `appCapabilities:{}` with no option. So we
     * patch that one literal; tests/Feature/Mcp/AppsTest asserts it still matches after package updates.
     */
    public static function sdk(array $modes): string
    {
        $sdk = (string) app('mcp.sdk');

        return $modes === [] ? $sdk : str_replace(
            'appCapabilities:{}',
            'appCapabilities:'.json_encode(['availableDisplayModes' => $modes]),
            $sdk,
        );
    }
}
