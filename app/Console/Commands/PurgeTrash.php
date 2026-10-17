<?php

namespace App\Console\Commands;

use App\Support\Trash\Trash;
use Illuminate\Console\Command;

// Force-deletes trashed rows older than config('onsplan.trash_days'). Scheduled daily (routes/console.php).
// Resumable by construction: each run only looks at what is still in the trash past the cutoff, so a
// crash mid-way just leaves the rest for the next run. Runs without a tenant (no CurrentFamily in the
// scheduler), so it covers every family. forceDelete() per model, not a bulk delete, so model events
// fire (Spend removes its receipt photos from storage in `forceDeleting`).
class PurgeTrash extends Command
{
    protected $signature = 'trash:purge';

    protected $description = 'Permanently remove items that have been in the trash longer than the retention window';

    public function handle(): int
    {
        $cutoff = now()->subDays(Trash::days());
        foreach (Trash::models() as $model) {
            $count = 0;
            $model::onlyTrashed()->where((new $model)->getDeletedAtColumn(), '<', $cutoff)
                ->chunkById(200, function ($rows) use (&$count) {
                    foreach ($rows as $row) {
                        $row->forceDelete();
                        $count++;
                    }
                });
            $this->line(class_basename($model).": {$count}");
        }

        return self::SUCCESS;
    }
}
