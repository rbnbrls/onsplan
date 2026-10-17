<?php

namespace Modules\Notifications;

// Registry of notification categories. Each feature module registers its own category from its
// service provider boot() (e.g. the tasks module registers 'tasks'), so the notifications module
// never hardcodes the list. Users toggle push per category in Settings; the label + icon drive the
// settings UI and the stacked-notification title. Registration is idempotent (boot re-runs per request).
class NotificationCategories
{
    /** @var array<string, array{label: string, icon: string}> */
    private static array $categories = [];

    public static function register(string $id, string $label, string $icon = 'bell'): void
    {
        self::$categories[$id] = ['label' => $label, 'icon' => $icon];
    }

    /** @return array<string, array{label: string, icon: string}> */
    public static function all(): array
    {
        return self::$categories;
    }

    public static function label(string $id): string
    {
        return self::$categories[$id]['label'] ?? 'onsplan';
    }

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_keys(self::$categories);
    }
}
