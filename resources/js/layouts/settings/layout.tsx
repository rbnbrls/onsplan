import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { Link, usePage } from '@inertiajs/react';

// key is a translation key resolved at render (so the nav is localized).
const sidebarNavItems: { key: string; url: string }[] = [
    { key: 'settings.household', url: '/settings/household' },
    { key: 'settings.profile', url: '/settings/profile' },
    { key: 'settings.password', url: '/settings/password' },
    { key: 'settings.appearance', url: '/settings/appearance' },
    { key: 'settings.language', url: '/settings/language' },
    { key: 'settings.media', url: '/settings/media' },
    { key: 'trash.title', url: '/settings/recently-deleted' },
    { key: 'mcp.connected_apps', url: '/settings/connected-apps' },
    { key: 'settings.calendar', url: '/settings/calendar' },
    { key: 'billing.title', url: '/settings/billing' },
];

// Modules with their own settings page, keyed by module id. Listed under a "Modules" label after the app's own
// sections, only when the family has that module enabled (`modules.enabled`, the same filter the app sidebar uses).
// A module page keeps its own URL and layout; this is just the one place to find them all. New module with a
// settings page? Add it here. (Notifications lives here, not above: it is a module, with per-module categories.)
const moduleSettingsItems: Record<string, { key: string; url: string }> = {
    spending: { key: 'nav.spending', url: '/m/spending/settings' },
    shopping: { key: 'nav.shopping', url: '/m/shopping/settings' },
    inventory: { key: 'nav.inventory', url: '/m/inventory/settings' },
    vehicles: { key: 'nav.vehicles', url: '/m/vehicles/settings' },
    expense_import: { key: 'nav.expense_import', url: '/m/expense-import/settings' },
    notifications: { key: 'nav.notifications', url: '/settings/notifications' },
};

export default function SettingsLayout({ children }: { children: React.ReactNode }) {
    const currentPath = window.location.pathname;
    const t = useT();
    // Billing only exists on the hosted plan (STRIPE_KEY set); self-hosted installs never see it.
    const { billingEnabled, modules } = usePage<{ billingEnabled?: boolean; modules?: { enabled?: string[] } }>().props;
    const items = sidebarNavItems.filter((item) => billingEnabled || item.url !== '/settings/billing');
    const enabled = new Set(modules?.enabled ?? []);
    const moduleItems = Object.entries(moduleSettingsItems)
        .filter(([id]) => enabled.has(id))
        .map(([, item]) => item);

    const navLink = (item: { key: string; url: string }) => (
        <Button
            key={item.url}
            size="sm"
            variant="ghost"
            asChild
            className={cn('w-full justify-start', {
                'bg-muted': currentPath === item.url,
            })}
        >
            <Link href={item.url} prefetch>
                {t(item.key)}
            </Link>
        </Button>
    );

    return (
        <div className="px-4 py-6">
            <Heading title={t('settings.title')} description={t('settings.manage')} />

            <div className="flex flex-col space-y-8 lg:flex-row lg:space-y-0 lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav className="flex flex-col space-y-1 space-x-0">
                        {items.map(navLink)}
                        {moduleItems.length > 0 && (
                            <>
                                <h3
                                    id="settings-modules-label"
                                    className="text-muted-foreground px-3 pt-4 pb-1 text-xs font-semibold tracking-widest uppercase"
                                >
                                    {t('nav.modules')}
                                </h3>
                                <div role="group" aria-labelledby="settings-modules-label" className="flex flex-col space-y-1">
                                    {moduleItems.map(navLink)}
                                </div>
                            </>
                        )}
                    </nav>
                </aside>

                <Separator className="my-6 md:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">{children}</section>
                </div>
            </div>
        </div>
    );
}
