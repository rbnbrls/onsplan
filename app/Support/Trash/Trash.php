<?php

namespace App\Support\Trash;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

// Reversible delete. Models that use SoftDeletes register here from their module provider so the daily
// `trash:purge` command (App\Console\Commands\PurgeTrash) knows what to force-delete once it has sat in
// the trash longer than config('onsplan.trash_days'). Keyed by class, so Octane re-registration is a
// no-op. Children trashed with their parent are listed too; whichever purges first, the rest follow
// (force-deleting a parent fires the DB cascades exactly as a hard delete always did).
class Trash
{
    /** @var array<class-string, true> */
    private static array $models = [];

    /** @var array<string, class-string<TrashSource>> module id => what that module shows/restores */
    private static array $sources = [];

    /**
     * A module's trash view: what this person may see in its trash and how to restore an entry. Used by
     * Settings > Recently deleted and by the module's MCP *_list_deleted / *_restore tools, so both agree.
     *
     * @param  class-string<TrashSource>  $source
     */
    public static function source(string $moduleId, string $source): void
    {
        self::$sources[$moduleId] = $source;
    }

    /** @return array<string, class-string<TrashSource>> */
    public static function sources(): array
    {
        return self::$sources;
    }

    /**
     * One trash entry, the shape every source returns.
     *
     * @return array{type: string, id: int, title: string, where: ?string, deleted_at: string, purged_on: string}
     */
    public static function row(Model $m, string $type, string $title, ?string $where = null): array
    {
        return [
            'type' => $type,
            'id' => $m->getKey(),
            'title' => $title,
            'where' => $where,
            'deleted_at' => $m->deleted_at->toIso8601String(),
            'purged_on' => $m->deleted_at->copy()->addDays(self::days())->toDateString(),
        ];
    }

    /** Newest first, across any rows. */
    public static function sorted(Collection $rows): Collection
    {
        return $rows->sortByDesc('deleted_at')->values();
    }

    /** @param  class-string  $model */
    public static function register(string $model): void
    {
        self::$models[$model] = true;
    }

    /** @return list<class-string> */
    public static function models(): array
    {
        return array_keys(self::$models);
    }

    public static function days(): int
    {
        return (int) config('onsplan.trash_days', 30);
    }
}
