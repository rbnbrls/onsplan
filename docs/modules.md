# Modules

onsplan keeps its **core** small (families, members, auth, sharing, realtime, the module system itself) and puts every feature in a **module**, a self-contained folder under `modules/`. A module has its own routes, controllers, models, migrations, and optionally a frontend bundle. Families turn modules on and off from **Settings → Modules**, and routes are gated so a module is only reachable when the family has enabled it.

Every feature is a bundled module (`tasks`, `shopping`, `spending`, `vehicles`, `recipes`, ...). `important_todos` is the smallest one and the best place to start reading: it extends tasks purely through hooks and a frontend slot.

## Anatomy of a module

```
modules/important_todos/
├── module.json                         # manifest
├── ImportantTodosServiceProvider.php   # registers routes, hooks, dashboard items, shareables
├── ImportantFlag.php                   # a model
├── database/migrations/                # module tables (run by `php artisan modules:migrate`)
│   └── 2026_..._create_important_todos_flags_table.php
└── resources/dist/important-todos.js   # optional prebuilt frontend bundle
```

### The manifest (`module.json`)

```json
{
    "id": "important_todos",
    "name": "Important Todos",
    "version": "1.0.0",
    "provider": "Modules\\ImportantTodos\\ImportantTodosServiceProvider",
    "frontend": "resources/dist/important-todos.js",
    "requires": ["tasks"]
}
```

| Field | Meaning |
|---|---|
| `id` | Unique slug, used in routes, the `module:<id>` gate, and the enable/disable UI. |
| `name` | Human-readable name shown in Settings → Modules. |
| `version` | Module version. |
| `provider` | The service provider class that boots the module. |
| `frontend` | Path to a prebuilt frontend bundle, or `""` for a backend-only module. |
| `requires` | Other module ids this one depends on. |

### The service provider

The provider's `boot()` method is where a module wires itself into the core. Routes are grouped behind the `module:<id>` middleware so they 404 for families that haven't enabled the module:

```php
namespace Modules\Example;

use App\Support\Modules\Hooks;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ExampleServiceProvider extends ServiceProvider
{
    public function boot(Hooks $hooks): void
    {
        Route::middleware(['web', 'auth', 'module:example'])->group(function () {
            Route::get('/m/example', [ExampleController::class, 'index'])->name('example.index');
        });
    }
}
```

Inside `boot()` a module can also:

- **React to and modify core behaviour via hooks**: `$hooks->listen('event', fn)` to run on an event, `$hooks->fire('event')` to emit one, and `$hooks->filter('name', $default)` to let modules transform a value.
- **Add items to the dashboard**: `DashboardItems::register('<id>', fn)` surfaces the module's own entries (for example, the spending module lists your recent events).
- **Make an entity shareable**: `ShareableRegistry::register('<type>', Model::class)` opts a model into the core sharing system (family / invited / private visibility, per-person roles, public links). See the spending module's `SpendEvent` for a full example.
- **Broadcast realtime updates**: `Realtime::broadcast(new SomeEvent($familyId))` pushes live changes to the family's private channel.

### Tenancy

All module data is scoped to a family. Use `App\Support\Tenancy\CurrentFamily::id()` for the active family, and the provided tenant scopes / `BelongsToFamily` trait so records are automatically filtered and stamped with the current family. Never trust a family id from the request, derive it from the session.

## Migrations

Module migrations live in `modules/<id>/database/migrations/` and are applied separately from the core:

```bash
php artisan modules:migrate
```

Run it after installing or upgrading a module.

## Frontend

A module may ship a prebuilt frontend bundle (referenced by `frontend` in the manifest) that the core loads when the module is enabled. Backend-only modules set `frontend` to `""`. The core frontend (shared UI, layouts, design system) lives in `resources/js`; follow its existing components and the design tokens in [docs/design/tokens.md](design/tokens.md) so new module UI stays consistent.

## Building your own

1. Copy `modules/important_todos` to `modules/<your-id>` and update `module.json`.
2. Rename the service provider and its namespace to `Modules\YourId`.
3. Add routes behind `['web', 'auth', 'module:<your-id>']`, plus any models and migrations.
4. `php artisan modules:migrate`, then enable it from **Settings → Modules**.

Read `modules/spending` for a complete, production module: controllers, models, multi-currency handling, receipts, sharing, and realtime.

## Exposing a module to AI assistants

A module can offer tools to the MCP server (Claude, ChatGPT, Cursor...) by registering them with
`McpTools::register()` from its provider. See [mcp.md](mcp.md).
