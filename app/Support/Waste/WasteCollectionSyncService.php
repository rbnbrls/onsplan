<?php

namespace App\Support\Waste;

use App\Models\FamilyWastePickup;
use App\Models\FamilyWasteSettings;
use App\Models\User;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Calendar\CalendarEvent;
use Modules\Tasks\TaskList;
use RuntimeException;

class WasteCollectionSyncService
{
    public function __construct(private WasteProviderRegistry $providers) {}

    public function sync(FamilyWasteSettings $settings, User $owner): int
    {
        if (! $settings->collector) throw new RuntimeException('Kies eerst een afvalinzamelaar.');
        $from = now()->startOfDay();
        $to = $from->copy()->addMonths(max(1, min(12, (int) $settings->sync_horizon_months)));
        $rows = $this->providers->provider($settings->collector)->fetch($settings, $from->toDateString(), $to->toDateString());
        $types = collect($settings->selected_waste_types ?: collect($rows)->pluck('type')->unique()->values()->all())
            ->map(fn ($v) => strtolower((string) $v))->all();
        if (! $settings->selected_waste_types) $settings->update(['selected_waste_types' => $types]);

        $list = $this->taskList($settings, $owner);
        $seen = [];
        DB::transaction(function () use ($rows, $types, $settings, $owner, $list, &$seen) {
            foreach ($rows as $row) {
                if (! in_array($row['type'], $types, true)) continue;
                $key = "family:{$settings->family_id}:{$settings->collector}:{$row['type']}:{$row['pickup_date']}";
                $seen[] = $key;
                $pickup = FamilyWastePickup::updateOrCreate(
                    ['family_id' => $settings->family_id, 'source_event_key' => $key],
                    ['waste_type' => $row['type'], 'waste_label' => $row['label'], 'pickup_date' => $row['pickup_date'], 'source_payload' => $row['raw'], 'source_hash' => hash('sha256', json_encode($row['raw'])), 'status' => 'active'],
                );
                $this->materialize($pickup, $settings, $owner, $list);
            }
            $stale = FamilyWastePickup::where('family_id', $settings->family_id)
                ->where('pickup_date', '>=', now()->toDateString())
                ->whereNotIn('source_event_key', $seen)->get();
            foreach ($stale as $pickup) {
                if ($pickup->calendar_event_id && ($event = CalendarEvent::find($pickup->calendar_event_id))) $event->delete();
                if ($pickup->task_id && ($task = $list->tasks()->find($pickup->task_id))) $task->delete();
                $pickup->update(['status' => 'removed']);
            }
        });
        $settings->update(['enabled' => true, 'status' => 'active', 'last_synced_at' => now(), 'last_error' => null]);
        return count($seen);
    }

    private function taskList(FamilyWasteSettings $settings, User $owner): TaskList
    {
        if ($settings->task_list_id && ($list = TaskList::find($settings->task_list_id))) return $list;
        $list = TaskList::where('name', 'Afval')->first();
        if (! $list) $list = TaskList::createFor($owner, ['name' => 'Afval', 'color' => 'green', 'icon' => 'recycle'], 'family');
        $settings->update(['task_list_id' => $list->id]);
        return $list;
    }

    private function materialize(FamilyWastePickup $pickup, FamilyWasteSettings $settings, User $owner, TaskList $list): void
    {
        $key = $pickup->source_event_key;
        $title = "{$pickup->waste_label} ophalen";
        $event = $pickup->calendar_event_id ? CalendarEvent::find($pickup->calendar_event_id) : CalendarEvent::where('source', 'waste_collection')->where('source_key', $key)->first();
        if (! $event) {
            $event = CalendarEvent::createFor($owner, CalendarEvent::validated([
                'title' => $title, 'notes' => 'Automatisch aangemaakt vanuit de afvalinzameling.', 'icon' => 'recycle', 'color' => 'green',
                'start_date' => $pickup->pickup_date->toDateString(), 'end_date' => $pickup->pickup_date->toDateString(),
                'start_time' => null, 'end_time' => null, 'repeat' => 'none', 'repeat_interval' => 1, 'repeat_until' => null,
            ]));
            $event->forceFill(['source' => 'waste_collection', 'source_key' => $key])->save();
        } else {
            $event->forceFill(['title' => $title, 'start_date' => $pickup->pickup_date, 'end_date' => $pickup->pickup_date, 'source' => 'waste_collection', 'source_key' => $key])->save();
        }

        $taskDate = $pickup->pickup_date->copy()->subDays((int) $settings->task_offset_days);
        $taskKey = $key.':put-out';
        $task = $pickup->task_id ? $list->tasks()->find($pickup->task_id) : $list->tasks()->where('source', 'waste_collection')->where('source_key', $taskKey)->first();
        $taskData = ['title' => "{$pickup->waste_label}-container buiten zetten", 'due_date' => $taskDate, 'source' => 'waste_collection', 'source_key' => $taskKey];
        if ($task) $task->forceFill($taskData)->save();
        else $task = $list->addTask($owner, ['title' => $taskData['title'], 'due_date' => $taskDate]);
        $task->forceFill(['source' => 'waste_collection', 'source_key' => $taskKey])->save();
        $pickup->update(['calendar_event_id' => $event->id, 'task_id' => $task->id, 'status' => 'active']);
    }
}
