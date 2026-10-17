<?php

namespace App\Support\Modules;

use Illuminate\Support\Facades\DB;

class ModuleRegistry
{
    /** Modules enabled for a newly created family; every other module starts opt-in. */
    public const DEFAULT_ENABLED_MODULES = ['calendar', 'contacts', 'tasks'];

    public function __construct(protected string $modulesPath) {}

    public function discover(): array
    {
        $out = [];
        foreach (glob($this->modulesPath.'/*/module.json') as $file) {
            $manifest = ModuleManifest::fromPath(dirname($file));
            $out[$manifest->id] = $manifest;
        }

        return $out;
    }

    public function manifest(string $id): ?ModuleManifest
    {
        return $this->discover()[$id] ?? null;
    }

    public function isInstalled(string $id): bool
    {
        return DB::table('modules')->where('module_id', $id)->exists();
    }

    public function markInstalled(string $id, string $version): void
    {
        DB::table('modules')->updateOrInsert(['module_id' => $id], ['version' => $version]);
    }

    public function isEnabled(string $id, int $familyId): bool
    {
        return in_array($id, $this->enabledIds($familyId), true);
    }

    /**
     * Enabled module ids for a family, ONE query per family per request. Every caller (shell shared
     * props, ShareModules, EnsureModuleEnabled, module controllers/hooks) routes through isEnabled(),
     * which used to run its own EXISTS: the app shell alone fired ~27 of them on every Inertia visit
     * (measured on /dashboard), i.e. half the queries of a page load, each a round trip to the
     * managed Postgres in prod. The memo lives on the current Request's attribute bag so it dies with
     * the request under Octane and per test request, no flush wiring.
     * ponytail: in console the bound Request lives for the whole process, so a long-running worker
     * would keep a family's enabled-set until restart. Nothing queued calls isEnabled() today (only
     * HTTP + the scheduled check commands); if a job ever needs live state, clear the bag per job.
     *
     * @return list<string>
     */
    public function enabledIds(int $familyId): array
    {
        $bag = app('request')->attributes;
        $key = "modules.enabled.{$familyId}";
        if (! $bag->has($key)) {
            $bag->set($key, DB::table('family_modules')
                ->where(['family_id' => $familyId, 'enabled' => true])
                ->pluck('module_id')->all());
        }

        return $bag->get($key);
    }

    /** Drop the per-request memo after a toggle so the same request sees the new state. */
    private function forgetEnabled(int $familyId): void
    {
        app('request')->attributes->remove("modules.enabled.{$familyId}");
    }

    public function enable(string $id, int $familyId): void
    {
        if (! $this->isInstalled($id)) {
            throw new \RuntimeException("Module [{$id}] is not installed.");
        }
        // Only a row flip. Never DDL (spec 12.2).
        DB::table('family_modules')->updateOrInsert(
            ['family_id' => $familyId, 'module_id' => $id],
            ['enabled' => true],
        );
        $this->forgetEnabled($familyId);
    }

    /** Enable the small default module set for a brand-new family. Existing families are untouched. */
    public function enableDefaults(int $familyId): void
    {
        $ids = DB::table('modules')->whereIn('module_id', self::DEFAULT_ENABLED_MODULES)->pluck('module_id');
        DB::table('family_modules')->insert($ids->map(fn ($id) => ['family_id' => $familyId, 'module_id' => $id, 'enabled' => true])->all());
        $this->forgetEnabled($familyId);
    }

    /** @deprecated Use enableDefaults(); retained for callers outside the onboarding flow. */
    public function enableAll(int $familyId): void
    {
        $this->enableDefaults($familyId);
    }

    public function disable(string $id, int $familyId): void
    {
        DB::table('family_modules')->updateOrInsert(
            ['family_id' => $familyId, 'module_id' => $id],
            ['enabled' => false],
        );
        $this->forgetEnabled($familyId);
    }
}
