# Primitives & Plugins

> **Status:** design draft. This proposes how onsplan evolves its existing module
> system into a **third-party plugin platform** built on **primitives** and **hooks**.
> Nothing here is built yet beyond the pieces called out under "What already exists".
> Read [modules.md](modules.md) first — this doc extends it.

## 1. The idea in one paragraph

Some things in onsplan are **primitives**: a to-do item, a note, a spend event —
each with a canonical data shape and canonical screens (a list row, an edit screen). A
primitive is registered once, in a known place, under a stable **type slug**
(`task`, `note`, `spend_event`). A **plugin** is a self-contained package that **extends
a primitive it does not own**, through named **hooks** the primitive exposes — without
editing the primitive's code. The motivating example: a plugin called *Important Todos*
adds an `important` flag to the task edit screen, persists it, and sorts important tasks
first — shipped as a folder you can drop in and delete cleanly, touching zero core files.

The goal we are designing for is a **third-party plugin ecosystem** (external developers
ship plugins), not just internal decoupling. That raises the bar sharply: hooks become a
**public, versioned API**, and running code you didn't write against real families' data
becomes a **security problem** that this document treats as the central concern (§8), not
an afterthought. Three proven ecosystems — Home Assistant, WordPress, and the sandboxed
hub platforms — have already walked this path; §7 distills what they got right and wrong.

## 2. Vocabulary

| Term | Meaning |
|---|---|
| **Primitive** | A first-class extensible thing with a stable **type slug** and canonical surfaces (model + serialized payload + list row + edit screen). E.g. `task`, `note`, `spend_event`. |
| **Capability** | A reusable cross-cutting behavior a primitive *opts into*: sharing, guest links, notifications, dashboard surfacing, images, realtime. Already implemented as trait + registry. |
| **Hook** | A named seam on a primitive that foreign code can attach to. Three kinds: **data**, **behavior**, **UI** (§5). |
| **Plugin** | A packaged extension (today's "module", generalized to third-party). Declares which primitives and hooks it binds to. |
| **Extension point** | A specific hook name a primitive publishes, e.g. `task.editor.fields` (UI) or `task.serialize` (data). The set of extension points is the platform's API surface. |

The distinction that matters: a **capability** is a primitive reaching *out* to a shared
service it chose. A **hook** is a plugin reaching *in* to a primitive it doesn't own.
Everything onsplan has today is the first kind. The plugin platform is the second kind.

## 3. What already exists (and what's missing)

onsplan is already ~70% of the way here. The primitives-and-capabilities layer is real
and reused across every feature module. What's missing is precisely the *reach-in*
direction.

**Already built:**

- **Module system** — `ModuleRegistry` discovers `modules/*/module.json`, per-family
  enablement is a row flip in `family_modules` (never DDL), tables are namespaced by
  module id, migrated via `php artisan modules:migrate`.
- **Capability opt-in pattern** — a model does `use Shareable` / `use GuestShareable` /
  `use BelongsToFamily` and calls one registry method in its provider's `boot()`. This is
  the pattern all hooks should imitate.
- **Type-slug identity** — every shareable primitive already has a canonical slug via
  `ShareableRegistry::register('task_list', TaskList::class)`. We already name primitives;
  we just do it once per capability instead of once per primitive.
- **A hooks bus, and it's live** — `app/Support/Modules/Hooks.php` implements
  WordPress-style `listen`/`fire` (actions) and `filter` (filters). It is **used today**:
  the tasks module fires `task.serialize`/`task.saved`/`task.query` and `important_todos`
  (`modules/important_todos/ImportantTodosServiceProvider.php`) listens to them. (Grep `\$hooks->`, not `Hooks::` —
  callers use the injected instance, not the static class.)
- **A working frontend slot runtime** — `resources/js/lib/modules.ts` provides a real
  `<Slot>` + `registerSlot`, and `app.tsx` exposes `window.onsplan = { React,
  registerSlot }` so a **prebuilt module bundle reuses the shell's React with no bundler
  and no second React**. Delivery is fully wired end to end: `ShareModules` middleware
  collects each enabled module's `frontend` URL → `app.blade.php` sets
  `window.__onsplanModules` → `loadModules()` dynamic-imports them →
  `ModuleAssetController` serves the file. `important_todos` ships `resources/dist/important-todos.js` that
  `registerSlot('task.editor.fields', …)` through exactly this path.
- **Dashboard extension** — `DashboardItems::register(id, closure)` lets a module push
  entries onto the cross-module dashboard, with per-provider failure isolation.
- **Frontend composition** — edit screens are already decomposed (e.g. `tasks/list.tsx`
  = page shell + reusable `<TaskTree>` + reusable `<ShareSheet>`), so injection has clean
  seams to target.

**Missing (this is the work) — narrower than it first looks.** The machinery for all three
hook kinds exists. What's absent is **adoption by a real
primitive**:

- **No primitive fires hooks.** Only the sample module used the bus. `TasksController` etc. publish
  no `task.serialize` / `task.saved` / `task.query` seams yet.
- **No slot is currently placed.** `dashboard.tsx:234` shows the one placement was
  *removed* ("Widgets slot removed for now"), so even the sample module's registered widget rendered
  nowhere. No primitive edit screen (e.g. `task-tree.tsx`) mounts a `<Slot>` yet.
- **`Slot` passes no context.** Today it renders `createElement(C, {key})` — a slot
  component gets no props, so it can only self-source data (as the sample widget did via
  globals). Per-instance injection ("a field *for this task*") needs `<Slot>` to forward a
  `ctx` — a small, backward-compatible change (§5.3).
- **No primitive registry.** The type slug is re-declared per capability; no single
  "primitives and their hooks" catalog.
- **No trust boundary.** Everything runs in-process at full trust. Fine for first-party
  modules; unacceptable for arbitrary third parties (§8).

**The `important_todos` module is the reference implementation** for this whole document:
it listens to the `task` primitive's hooks on the backend and `registerSlot`s into the task
edit screen on the frontend (worked example, §11).

## 4. The primitive model

A **primitive** is registered once, unifying the slug that today is scattered across
`ShareableRegistry`, `GuestableRegistry`, `NotificationCategories`, and `DashboardItems`:

```php
// In the owning module's ServiceProvider::boot()
PrimitiveRegistry::register('task', [
    'model'      => Task::class,
    'capabilities' => ['shareable' => TaskList::class], // parent owns sharing
    'serialize'  => fn (Task $t) => [ /* the canonical payload */ ],
    'hooks'      => [
        // declares which extension points this primitive publishes — the API surface
        'data'     => ['task.serialize'],
        'behavior' => ['task.created', 'task.saved', 'task.deleted', 'task.query'],
        'ui'       => ['task.editor.fields', 'task.row.badges'],
    ],
]);
```

The registry is the **catalog**: it answers "what primitives exist, what's each one's
slug, and what hooks does each publish." A plugin reads this catalog (statically, from
manifests) to know what it may bind to. Publishing the hook list here is what turns hooks
from ad-hoc `fire()` calls into a **declared, discoverable, versionable contract**.

**Plugins may register *new* primitives, not just extend existing ones.** This is the
WordPress **custom post type** lesson (§7): WooCommerce didn't ask WordPress core to add a
"product" — it called `register_post_type()` and declared one, and that single decision is
what made the ecosystem. A onsplan plugin calling `PrimitiveRegistry::register()` for
its own entity gets sharing, notifications, and dashboard surfacing for free, exactly as a
first-party module does. (The trust bar for this is higher than for extending — see §13.)

## 5. The three kinds of hook

Our one worked example — "important todos adds a flag" — needs all three. They live in
different parts of the stack and have different mechanics.

### 5.1 Data hooks — shape the payload (filters)

A plugin needs `important` to (a) have somewhere to live and (b) appear on the Task
payload the frontend receives.

- **Storage:** the plugin owns an **extension table** it creates in its own migration —
  e.g. `important_todos_flags (task_id, important)`. **Plugins never alter core/other-module
  tables.** (See §8.6 on data ownership.) This mirrors WordPress **post meta**: extra data
  hangs off the primitive by foreign key rather than mutating its schema.
- **Payload:** the primitive runs its serialized array through a filter before sending:

```php
// Primitive (TasksController), when building the Inertia payload:
$payload = $hooks->filter('task.serialize', $task->toBasePayload(), $task);

// Plugin (ImportantTodosServiceProvider::boot()):
$hooks->listen('task.serialize', function (array $payload, Task $task) {
    $payload['important'] = ImportantFlag::for($task)?->important ?? false;
    return $payload;
});
```

### 5.2 Behavior hooks — react and constrain (actions + filters)

- **Persist on save** (action): the primitive fires an event; the plugin writes its table.

```php
// Primitive: after saving a task
$hooks->fire('task.saved', $task, $request);

// Plugin
$hooks->listen('task.saved', function (Task $task, Request $req) {
    ImportantFlag::set($task, (bool) $req->boolean('important'));
});
```

- **Sort important first** (filter on the query builder):

```php
// Primitive: before running the task list query
$query = $hooks->filter('task.query', Task::query(), $context);

// Plugin
$hooks->listen('task.query', fn ($q) => $q->orderByDesc(/* joined important flag */));
```

`task.created` / `task.deleted` round out the lifecycle (the delete hook is also how a
plugin cleans up its extension rows — though cascade-on-uninstall is the backstop, §8.6).

### 5.3 UI hooks — inject rendered components (frontend slots)

The backend can't do this; React must. The primitive's edit screen declares **named slots**,
and plugin bundles **register components** into them — WordPress's **meta box** pattern
(`add_meta_box`) and Home Assistant's **custom card** pattern, in React. The runtime for this
**already existed** (`resources/js/lib/modules.ts` — `<Slot>` + `registerSlot`, loaded via
`window.onsplan`); the only change needed to ship the example was teaching `<Slot>` to
forward a `ctx` to its children (it previously passed none, so a slot component could only
self-source data). Below is the shipped shape:

```tsx
// Primitive edit screen (inside <TaskTree>'s editor). ctx is spread as props onto each
// registered component; `onPatch` keeps the router in the primitive — the module only names
// the field to persist. See resources/js/components/tasks/task-tree.tsx.
<Slot name="task.editor.fields" ctx={{ task, onPatch: (data) => router.patch(taskUrl(task.id), data) }} />
<Slot name="task.row.badges"   ctx={{ task: item }} />

// Plugin frontend bundle — a prebuilt file using the shell's React (no bundler), like
// modules/important_todos. Loaded only for families that enabled the module.
const { React, registerSlot } = window.onsplan;
registerSlot('task.editor.fields', ({ task, onPatch }) => /* an Important toggle → onPatch({important}) */);
registerSlot('task.row.badges',   ({ task }) => task.important ? /* a star */ : null);
```

`<Slot>` renders every component registered under its name, in registration order,
passing a **frozen context** (`ctx`) — the primitive decides exactly what data and
callbacks a slot exposes. `patch` is the primitive's own state updater; the plugin never
touches primitive internals, only the context it's handed. This keeps the injection
surface small and explicit, which matters enormously for §8.

**Delivery:** the plugin's registrations live in its `frontend` bundle
(`module.json.frontend`, the dormant field). When a family has the plugin enabled, the
core loads that bundle and its `registerSlot` calls run. This is the intended use of the
already-designed-but-unused `frontend` field.

## 6. Hooks as a versioned public API

The moment third parties bind to `task.serialize`, that name and its payload shape are a
**contract**. Breaking it breaks their plugins. So:

- **The hook catalog is versioned** with the platform (semver). Each primitive's published
  hook list (§4) carries a version. Renaming or removing a hook, or changing a slot's
  `ctx` shape, is a **breaking change**.
- **Plugins declare the hooks they bind** in their manifest (§10). At install time the
  platform checks every declared hook still exists at a compatible version; a plugin
  targeting a removed hook fails to install rather than silently no-op'ing.
- **Deprecation, not deletion.** A superseded hook keeps firing (optionally alongside its
  replacement) for a deprecation window, logged, before removal in a major version. This
  is where WordPress's discipline shows: it keeps decade-old hooks alive rather than break
  the ecosystem — backward compatibility is the platform's product.
- **Additive by default.** New hooks and new `ctx` fields are non-breaking. Design slot
  contexts to grow (pass an object, never positional args).

This is the difference between "internal decoupling" (rename freely) and "third-party
platform" (the hook names are as load-bearing as a REST API).

## 7. Prior art: how proven ecosystems do this

Three mature ecosystems have already built "primitives + hooks + third-party plugins." The
architecture converges; the trust models diverge, and that divergence is the lesson.

### 7.1 WordPress — the direct ancestor (and the warning label)

Your `Hooks` bus is explicitly WordPress-modeled, so the mapping is 1:1:

| WordPress | onsplan |
|---|---|
| `do_action` / `add_action` | `Hooks::fire` / `listen` |
| `apply_filters` / `add_filter` | `Hooks::filter` |
| **Meta box** (`add_meta_box`) — inject a panel into the edit screen | UI slot `task.editor.fields` |
| **Post meta** (`add_post_meta`) — extra data by FK, no schema change | Extension table (§5.1) |
| **Custom post type** (`register_post_type`) — a plugin declares a *new* primitive | `PrimitiveRegistry::register` by a plugin (§4) |
| Plugin header + `register_activation_hook` / `uninstall.php` | `module.json` + lifecycle (§10) |

What WordPress teaches:

- **Do: hook density.** WP is extensible because core fires *thousands* of hooks on nearly
  every path. Your bus fires zero. Publish hooks liberally.
- **Do: one flexible primitive + generic extension bags.** post + post meta + taxonomies
  let plugins extend and instantiate primitives without core changes. This is the model
  for §4.
- **Don't: full-trust in-process + weak review + host untrusted at scale.** WP plugins run
  as unrestricted PHP (`$wpdb` on any table, any HTTP, filesystem, `exec`), with **no
  capability declaration** — god-mode by default. The wordpress.org review is light and
  **updates aren't re-reviewed**, so compromised plugin accounts have pushed malware to
  millions of sites via auto-update. Plugins are the leading cause of WP site compromise.
- **Don't: skip uninstall hygiene.** Most plugins leave orphaned rows in `wp_options` and
  their own tables. §8.6 makes cleanup first-class *because* WP shows the cost of not.

**The data point that settles our deployment split (§8.3):** WordPress**.com** — the
*hosted, multi-tenant* product built by the people who make WordPress — **does not let you
install arbitrary plugins**. Only higher-paid tiers, from a curated set, in a hardened
environment. Self-hosted WordPress allows anything because it's *your* server. The same
product runs exactly the split we propose, for exactly our reasons.

### 7.2 Home Assistant — the same model, done with discipline

HA is the cleanest version of "reviewed core + at-your-own-risk community":

- **Core integrations** — reviewed, in-repo, ship with HA. Each has a `manifest.json` with
  declared `requirements` (dependencies), `codeowners`, `config_flow`, and a
  **quality_scale** (Bronze→Platinum). Quality/trust is a *graduated scale*, not a binary.
- **HACS** (community store) — third-party integrations and custom cards, **explicitly
  at-your-own-risk, no sandbox** (full Python in-process, full-trust JS cards). HA's answer
  to the trust problem is to label it loudly and push the risk to the user — which is
  coherent *because HA runs on the user's own hardware with the user's own data*.
- **Config flow** — plugins *declare* their setup as steps; core renders the UI. Less
  untrusted frontend code, consistent UX. Worth stealing into §10.
- **Entity/device registry** — stable ids decoupled from the integration, mirroring our
  primitive type-slug identity.

Steal from HA: the **quality scale**, **declared requirements/capabilities**, and
**config-flow** (declare config, don't ship UI).

### 7.3 The sandboxed hubs — the road not taken (yet)

Not everyone accepts full trust:

- **SmartThings** — old cloud SmartApps ran in a Groovy sandbox; new **Edge drivers** run
  in a **Lua VM on the hub**. Actually isolated.
- **Homey** — apps run in **sandboxed Node.js** with a **permission/scope system declared
  in the manifest**, reviewed store. Runtime-enforced capabilities, not just review.

The trade: real isolation, but you must **build and own a constrained runtime and a narrow
API** — you can't just `import` anything. This is the endgame for a fully open ecosystem
(§8.4/§8.5, §12 Phase 5), not a starting point.

### 7.4 The convergence

Everyone lands on **actions/filters (or an event bus) + a reviewed first-party tier + a
looser community tier**. The only real variable is *where the trust boundary sits*:
WordPress/HA push it to the user and accept the risk; SmartThings/Homey build a sandbox and
pay for it. onsplan's twist — hosted, multi-tenant, shared family data — is why §8 can't
just copy HACS wholesale.

## 8. Trust & deployment: who can run what

For **internal** extensibility this section is nearly empty — first-party modules are
trusted. For **third-party** plugins it is the whole game. onsplan holds families'
private financial and personal data; a malicious or buggy plugin is a data-breach vector.

### 8.1 The honest constraint

- **In-process PHP is full trust.** A plugin's `ServiceProvider` runs in the same process
  as core. It can read any table, ignore the tenant scope, reach any family's data. PHP
  has no in-process sandbox worth trusting. **You cannot safely run arbitrary untrusted PHP
  in your app process — full stop.** (WordPress is the proof at scale, §7.1.)
- **In-page JS is full trust too.** A plugin bundle loaded into the SPA shares the DOM, the
  session cookie, and every Inertia prop. Arbitrary plugin JS can exfiltrate everything the
  logged-in user can see.

So "any developer uploads a plugin and it runs on hosted onsplan" is **not** a safe v1.
The resolution is two axes: a **trust tier** for the plugin (§8.2) and the **deployment**
it runs in (§8.3).

### 8.2 The three-tier trust model

Not every extension needs to run code. Sort plugins by *what they're allowed to do*:

1. **Declarative extensions — no code, no sandbox, no review needed.** A JSON description
   the platform interprets against a fixed, safe vocabulary — the plugin never executes.
   Shareable freely, hosted-safe, installable by anyone — the onsplan equivalent of a
   Home Assistant **blueprint** (a pure-config extension you import with no risk). The
   validation in §14 (14 real plugin ideas) shows **~70% of plausible plugins are
   declarative**, and that this tier splits cleanly into two sub-levels worth building
   separately:
   - **1a — fields + display + sort/filter/preset (cheap, ship first).** "Add typed data to
     a primitive and show it": e.g. add boolean `important` to `task`, render a toggle in
     `task.editor.fields`, a star in `task.row.badges`, sort by it. A small, obviously-safe
     interpreter over a fixed vocabulary of field types, slot controls, and query modifiers
     (§14.2). **Our entire worked example fits here.** A generic "custom fields" plugin *is*
     this engine exposed as UI.
   - **1b — a rules engine: triggers → conditions → actions + aggregation (later, bounded).**
     The *smart* plugins — due-date reminders, category budgets with alerts, recurring-lite —
     are still code-free but need the platform to ship a trigger/action engine. This is
     precisely Home Assistant automations/blueprints, which are declarative *because* HA
     built that engine underneath. A real, separate investment (see §12 Phase 3b) — HA proves
     it pays off, but it is not required to launch the declarative tier.
2. **Reviewed code plugins — real logic, marketplace, signed + reviewed.** For anything
   declarative can't express (custom processing, external API calls à la the receipt
   scanner). Runs in-process, but only code that passed review + a capability static-check
   (§8.4). Hosted-safe *because of the gate*, not the runtime.
3. **At-your-own-risk code plugins — self-host, or hosted behind isolation.** The HACS
   tier: drop a folder in `modules/`, unreviewed, full-trust. Coherent on your own box;
   gated hard on hosted (§8.3).

The design win: push as much as possible into tier 1, so the ecosystem can grow *without*
a trust problem, and you only pay review/sandbox costs for genuine code.

### 8.3 The deployment-aware split

The blast radius of "at your own risk" depends on whose box and whose data. Make the split
follow the deployment, validated by WordPress.com (§7.1):

| | **Self-hosted onsplan** | **Hosted (multi-tenant SaaS)** |
|---|---|---|
| Tier 1 (declarative) | ✅ anyone | ✅ anyone |
| Tier 2 (reviewed code) | ✅ | ✅ marketplace only |
| Tier 3 (at-your-own-risk code) | ✅ drop-in folder, your box, your data, your call | 🚫 blocked until sandboxing exists (§8.4/§8.5) |

"At your own risk" is coherent self-hosted (your server, your family's data). It is *not*
coherent hosted, because the risk lands on **other family members** who didn't choose the
plugin and on **your infrastructure and liability**. This is exactly why WordPress.com
restricts plugins while self-hosted WordPress doesn't.

### 8.4 Backend isolation (for tier 3 on hosted, later)

To ever allow unreviewed code on hosted infra:

- **Capability manifest + static analysis (cheaper).** The plugin declares tables, hooks,
  and outbound network it uses (§10); CI statically rejects raw `DB::` on non-owned tables,
  `CurrentFamily` bypass, and undeclared HTTP. Reduces — doesn't replace — review. This is
  Homey's model minus the runtime enforcement.
- **Out-of-process (real isolation, expensive).** Plugins run as separate
  processes/containers, interacting only through narrow RPC/webhooks with per-request
  scoped tokens. This is SmartThings Edge / a true sandbox. Large latency + complexity
  cost; a Phase 5 option, not a starting point.

**Non-negotiable in every model:** tenancy is enforced by the *platform*, not the plugin.
Hooks hand plugins data already scoped to the current family (the `ctx`, the `$task`); the
platform never passes a raw, unscoped query a plugin could widen to another family, and
`CurrentFamily::id()` derives from the session, never the request.

### 8.5 Frontend isolation

- **Tiers 1–2: reviewed / declarative components** register into slots directly (§5.3).
  Simple and fast; safety comes from review (tier 2) or from not executing at all (tier 1).
- **Tier 3 on hosted: sandboxed slot renderers.** Untrusted UI renders in a locked-down
  `<iframe>` (or Web Worker + serializable VDOM bridge) that receives only the slot `ctx`
  and can post back only declared actions — no session cookie, no host DOM, no other props.
  Higher effort; the prerequisite before opening slots to unreviewed hosted plugins.

### 8.6 Data ownership & lifecycle

- **Plugins own their tables**, namespaced by plugin id (`important_todos_*`), created in
  their own migrations. Enforce with the existing `ModuleNamespace::assertTable` guard.
- **Plugins never write core or other-plugin tables.** They attach by foreign key from
  their own table and expose data via the `serialize` filter (WP post-meta pattern).
- **Uninstall is a real operation.** Disabling is a row flip (data retained). *Uninstall*
  drops the plugin's tables / purges its rows so nothing personal is orphaned — WordPress's
  orphaned-data problem (§7.1) is the thing to avoid, and it matters doubly for self-host
  and privacy.
- **Referential safety on primitive delete.** When a primitive row is deleted, its
  extension rows go too (plugin listens to `*.deleted`, with a platform cascade as backstop
  so a broken plugin can't leak rows forever).

## 9. Frontend slot system (design detail)

- **`registerSlot(name, Component)`** — adds a renderer; called at bundle load.
- **`<Slot name ctx />`** — renders all registrations for `name`, each wrapped in an error
  boundary so one plugin can't white-screen the host (mirror `DashboardItems`' per-provider
  failure isolation, which already does this server-side).
- **Frozen context.** `ctx` is the *only* channel. It carries primitive data plus a
  minimal, explicit callback set (e.g. `patch`). Never pass the raw model, the router, or
  Inertia props into a slot.
- **Ordering & keys.** Registration order is stable; slots that render list-adjacent
  content (badges) get a stable key from the primitive id.
- **Enablement.** A slot only renders registrations from plugins the current family has
  enabled — enforced by loading only enabled bundles, matching how `modules.enabled` in
  `HandleInertiaRequests::share()` already gates sidebar links.

## 10. Plugin manifest & lifecycle

Extend `module.json` (superset — existing modules stay valid). Fields marked NEW borrow
directly from Home Assistant's `manifest.json` (§7.2):

```jsonc
{
  "id": "important-todos",
  "name": "Important Todos",
  "version": "1.0.0",
  "provider": "Modules\\ImportantTodos\\ImportantTodosServiceProvider",
  "frontend": "resources/dist/important-todos.js",
  "requires": ["tasks"],                 // depends on the tasks primitive being present

  "tier": "declarative",                 // NEW: declarative | reviewed | community (§8.2)
  "quality_scale": "silver",             // NEW: HA-style graduated quality signal
  "extends": {                           // NEW: the declared contract, checked at install
    "primitives": ["task"],
    "hooks": [
      "task.serialize", "task.saved", "task.query",
      "task.editor.fields", "task.row.badges"
    ],
    "platform": "^1.0"                    // compatible hook-contract version
  },
  "config_flow": true,                   // NEW: declares config schema; core renders it (HA)
  "capabilities": {                      // NEW: for static analysis / review (§8.4)
    "tables": ["important_todos_flags"],
    "network": []                        // outbound hosts it may call (empty = none)
  }
}
```

Lifecycle, reusing the existing machinery:

- **Discover / install** — `ModuleRegistry` globs manifests; install validates
  `extends.hooks` against the live primitive catalog and `platform` against the current
  hook-contract version. On **hosted**, install also enforces the tier/deployment matrix
  (§8.3): tier `community` code is refused; tier `reviewed` requires a signature/review
  status; tier `declarative` is always allowed.
- **Enable (per family)** — row flip in `family_modules` (unchanged); loads the frontend
  bundle for that family.
- **Disable** — row flip off; data retained.
- **Uninstall** — drops plugin tables / purges its rows (§8.6).
- **Migrate** — `php artisan modules:migrate` (unchanged; guarded by `ModuleNamespace`).

## 11. Worked example: `important_todos` — BUILT and verified

This is no longer hypothetical. The module ships at `modules/important_todos/` and was
verified end-to-end on the live app (login → toggle Important in a task's editor → star
badge on the row → important task sorts to top → persisted in `important_todos_flags`), with
the full PHP suite green (247 passing) and **zero** edits to tasks-module logic beyond the
seams in §5. It adds a field, a display, and a sort — squarely **tier-1 declarative** in
spirit (§8.2), though shipped in code form to exercise all three hook kinds.

What it contains: a manifest (`extends: [task]`, `frontend` bundle), a migration for
`important_todos_flags` (FK to `tasks_tasks`, cascade-on-delete = automatic cleanup), a
model, a provider binding `task.serialize`/`task.saved`/`task.query`, and a prebuilt
`resources/dist/important-todos.js` registering the two slots. Delete the folder and the
feature vanishes without a trace.

**Gotchas the build hit — read these before writing a plugin** (each was a real bug caught in
testing, fixed at root cause; they define the actual contract):

- **Module id must be underscore, not hyphen.** `ModuleNamespace::assertTable` forces every
  table to start with `{id}_`, and SQL identifiers can't contain hyphens. So `important_todos`,
  table `important_todos_flags`. (§10's earlier `important-todos` spelling was wrong.)
- **Every listener MUST self-gate on `isEnabled`.** Module providers boot for *all* families,
  and these hooks fire on ordinary (ungated) task requests — so without the gate the listeners
  run for families that never enabled the module, and query the flags table before
  `modules:migrate` created it (500s for everyone). The tasks module's `DashboardItems`
  closures use the same self-gate.
- **`task.query` receives an Eloquent `Builder`, via `getQuery()`.** `$list->tasks()` is a
  `HasMany` *relation*, not a Builder; the primitive must hand the hook the underlying builder
  or a typed `Builder $query` listener throws. The primitive also table-qualifies its columns
  and pins `select('tasks_tasks.*')` so a module JOIN can't make `id` ambiguous or pollute the
  models.
- **An extension-only update must not look like a done-toggle.** `updateTask` treats an
  *empty* body as "toggle done" — a body carrying only a module field (`{important:true}`)
  falls through to the edit branch (no-op on core fields, then `task.saved` fires). Getting
  this wrong flipped `done` as a side effect of marking important.
- **Postgres sorts NULLs FIRST on `DESC`.** A `leftJoin` leaves NULL for un-flagged tasks, so
  `orderByDesc('important')` pushed important tasks *below* un-flagged ones. Use a portable
  `CASE WHEN important THEN 1 ELSE 0 END DESC`.
- **The optimistic mirror re-syncs on known fields only.** `TaskTree`'s `serverKey` hashes
  id/done, so a plugin field can lag until the next structural change. Acceptable here; a
  general fix would let a slot contribute to the re-sync key.

To run it: `php artisan modules:migrate`, then enable **Important Todos** in Settings →
Modules for a family.

## 12. Phased rollout

Trust and effort both argue for building this **inward-out**: prove the hook API on
first-party code before exposing it to anyone.

- **Phase 1 — Dogfood (internal, full trust). ✅ DONE.** The `task` primitive publishes its
  data + behavior hooks (`task.serialize`/`task.saved`/`task.query`); the `Hooks` bus was
  already live. Ergonomics validated by the module below.
- **Phase 2 — Frontend slots (internal). ✅ DONE.** The `<Slot>`/`registerSlot` runtime
  already existed; adding `ctx` forwarding + placing two slots in the task UI + shipping
  `important_todos` as a first-party module proved the reach-in direction end to end (§11),
  with no third-party risk. **Next real work starts at Phase 3a.**
- **Phase 3a — Declarative tier 1a + contract (§8.2).** Freeze the primitive catalog +
  hook versions; add the manifest `extends`/`tier`/`capabilities` fields and install-time
  validation; build the small fields+display+sort/filter/preset interpreter (§14.2). Now
  anyone can ship safe, no-code "add a field and show it" plugins — ~half of all plugin
  ideas (§14), at low cost and zero trust risk.
- **Phase 3b — Declarative tier 1b (rules engine).** Add triggers → conditions → actions +
  aggregation (HA-blueprint-style). Unlocks the *smart* declarative plugins (reminders,
  budget alerts, recurring-lite) without opening the code door. A larger, self-contained
  investment; sequence it against demand, not before.
- **Phase 4 — Reviewed marketplace (§8.2 tier 2). This is the hosted ceiling (decision
  #1).** Signing/review + capability static analysis + real uninstall. Third-party *code*
  ships on hosted, through a gate. Self-host gains the at-your-own-risk drop-in folder
  (tier 3) — WordPress/HACS model. **No Phase 5 on hosted:** we deliberately stop here
  rather than build sandboxing for unreviewed hosted code (§13 decision #1).

## 13. Open decisions

1. **Trust target for hosted "third-party" — resolved: stop at reviewed.** Hosted
   onsplan caps at Phase 4: declarative (tier 1) for anyone, reviewed+signed code
   (tier 2) through the marketplace, and **no unreviewed code and no sandboxing investment**
   (no Phase 5). This is where WordPress.com and Home Assistant's core tier sit. Unreviewed,
   full-trust code (tier 3) remains a **self-host-only** option (§8.3). Revisit only if a
   real open-hosted-ecosystem demand appears that the reviewed marketplace can't serve.
2. **Self-host latitude.** Confirmed direction (§8.3): self-hosters may drop tier-3 folders
   in `modules/` at their own risk, as with self-hosted WordPress. Any guardrails, or fully
   hands-off?
3. **Slot granularity.** How many slots per primitive? Start minimal (`editor.fields`,
   `row.badges`) and add on demand — each slot's `ctx` is an unversionable liability.
4. **New primitives from plugins — resolved: yes.** Following WordPress custom post types
   (§7.1), a plugin may `PrimitiveRegistry::register` its own entity and inherit sharing /
   notifications / dashboard. Open sub-question: does a plugin-registered primitive get the
   *full* capability set automatically, or opt in per capability? *Leaning:* opt-in, to keep
   the surface explicit.
5. **How much fits the declarative tier? — resolved: validated at ~70%, split 1a/1b.** The
   §14 validation (14 real plugin ideas) confirms the bet and refines it: ~half fit the
   cheap **tier 1a** (fields+display+sort), a further chunk need the **tier 1b** rules
   engine, and only genuinely code-shaped plugins (network, ML, stateful, custom output)
   fall to reviewed code. Open sub-question: the exact tier-1a field-type and slot-control
   vocabulary (§14.2) — start minimal and grow additively (§6).
6. **Cross-plugin dependencies.** `requires` handles "needs tasks present"; do we allow a
   plugin to extend *another plugin's* primitive? Powerful, but multiplies the versioning
   surface.

## 14. Appendix: declarative tier validation

### 14.1 Does "a large fraction" hold? (14 real plugin ideas)

Each idea mapped to what it actually needs, against onsplan's real primitives
(`task`, `note`, `spend`, `shopping`, `project`). Tiers per §8.2.

| # | Plugin idea | Primitive | What it needs | Tier |
|---|---|---|---|---|
| 1 | Important todos | task | bool field + star badge + sort | **1a** |
| 2 | Priority levels (low/med/high) | task | enum field + colored badge + sort | **1a** |
| 3 | Task labels / tags | task | taxonomy field + filter | **1a** |
| 4 | Note reading-time badge | note | computed display over `body`, no storage | **1a** |
| 5 | Note starter templates | note | preset library, seed `body` on create | **1a** |
| 6 | Expense tags | spend | taxonomy field + filter | **1a** |
| 7 | Custom fields (any primitive) | * | *is the tier-1a authoring UI itself* | **1a** |
| 8 | Task due-date reminder | task | temporal trigger → notify assignee | **1b** |
| 9 | Category budget + alert | spend | aggregation + threshold trigger + progress bar | **1b** |
| 10 | Recurring expenses | spend | schedule → materialize next row | **1b / 2** |
| 11 | Bank / CSV import | spend | external network + parsing | **2** |
| 12 | Weekly spending digest email | spend | schedule + aggregate + compose + send | **2** |
| 13 | Task time tracking | task | stateful live timer + accumulate | **2** |
| 14 | Receipt OCR | spend | external / ML processing | **2** |

**10 / 14 declarative** (7 in cheap 1a, 3 needing the 1b engine), **4 need code.** The
appealing "smart" plugins cluster in 1b — which is why 1b is worth building, but as its own
phase (§12 Phase 3b). Idea #10 is the boundary marker: its field is 1a, its scheduling is
1b-or-code; when unsure, a plugin falls to reviewed code.

### 14.2 The tier-1a vocabulary (start minimal, grow additively)

The interpreter is safe precisely because it's a *closed* vocabulary — no expression
evaluation, no arbitrary code. A tier-1a plugin manifest carries an `extends` block:

```jsonc
{
  "id": "important-todos", "tier": "declarative",
  "extends": {
    "primitive": "task",
    "fields": [
      { "key": "important", "type": "boolean", "default": false }
      // types: boolean | enum | number | text | date | tag | reference
    ],
    "display": [
      { "slot": "task.editor.fields", "control": "toggle", "field": "important",
        "label": "Important" },
      { "slot": "task.row.badges", "control": "icon", "field": "important",
        "when": true, "icon": "star" }
      // controls: toggle | select | number | text | date | progress | icon | badge | computed
    ],
    "sort":   [ { "field": "important", "direction": "desc" } ],
    "filter": [ { "field": "important", "control": "toggle" } ]
    // "preset": [...] seeds fields/children on create (idea #5)
  }
}
```

Everything a tier-1a plugin does reduces to: **declare fields** (stored in the plugin's own
extension table, §8.6), **bind them to slot controls** (§5.3), and **modify the primitive's
query** (sort/filter). No callback, no fetch, no computation beyond the fixed `computed`
controls (e.g. word-count). Adding a field type or control is an *additive, non-breaking*
platform change (§6) — so the vocabulary can grow with demand without ever breaking a
shipped plugin. Tier 1b extends this same manifest with a `rules` block (triggers →
conditions → actions), interpreted by the engine built in Phase 3b.

---

*Related: [modules.md](modules.md) (the current module system), the `Hooks` bus at
`app/Support/Modules/Hooks.php`, and the capability registries under `app/Support/`.*
