<?php

namespace App\Console\Commands;

use App\Models\Family;
use App\Models\FamilyWasteSettings;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Waste\WasteCollectionSyncService;
use Illuminate\Console\Command;

class SyncWasteCollections extends Command
{
    protected $signature = 'waste:sync {family_id?}';
    protected $description = 'Synchroniseer afvalophaalmomenten naar agenda en taken';

    public function handle(WasteCollectionSyncService $sync): int
    {
        $families = $this->argument('family_id') ? Family::whereKey($this->argument('family_id'))->get() : FamilyWasteSettings::where('enabled', true)->get()->map(fn ($s) => Family::find($s->family_id))->filter();
        foreach ($families as $family) {
            $settings = FamilyWasteSettings::withoutGlobalScopes()->where('family_id', $family->id)->first();
            if (! $settings || ! $family->owner_id) continue;
            CurrentFamily::set($family->id);
            try { $count = $sync->sync($settings, $family->members()->whereKey($family->owner_id)->firstOrFail()); $this->info("Family {$family->id}: {$count} ophaalmomenten"); }
            catch (\Throwable $e) { $settings->update(['status' => 'error', 'last_error' => mb_substr($e->getMessage(), 0, 1000)]); $this->warn("Family {$family->id}: {$e->getMessage()}"); }
            finally { CurrentFamily::forget(); }
        }
        return self::SUCCESS;
    }
}
