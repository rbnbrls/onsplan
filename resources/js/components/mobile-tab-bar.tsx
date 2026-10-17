import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { runAddAction } from '@/lib/add-action';
import { useT } from '@/lib/i18n';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Blocks,
    Cake,
    CalendarDays,
    Car,
    ChefHat,
    Contact,
    FolderKanban,
    Import,
    LayoutGrid,
    ListChecks,
    Loader2,
    type LucideIcon,
    MoreHorizontal,
    Package,
    Pin,
    Plus,
    Settings,
    ShoppingCart,
    StickyNote,
    Users,
    Wallet,
} from 'lucide-react';
import { useEffect, useState } from 'react';

// The DS floating mobile navigation: a translucent, blurred pill above the content. It shows up to
// four PINNED destinations plus a "More" button that opens a drawer with everything else; the pinned
// set is user-selectable (pin/unpin in the More drawer, saved per device). Desktop uses the sidebar.
// `method: 'post'` marks a quick-add that creates server-side on click (e.g. a new note is made
// immediately and you land in its editor) rather than deep-linking to an index that opens a sheet.
// labelKey is a translation key resolved at render (localized nav labels).
type Item = { key: string; labelKey: string; url: string; icon: LucideIcon; method?: 'post' };

const HOME: Item = { key: 'dashboard', labelKey: 'nav.home', url: '/dashboard', icon: LayoutGrid };
// Page-modules earn a nav entry when the family has enabled them (order = preferred display order).
const MODULE_ITEMS: Item[] = [
    { key: 'projects', labelKey: 'nav.projects', url: '/m/projects', icon: FolderKanban },
    { key: 'calendar', labelKey: 'nav.calendar', url: '/m/calendar', icon: CalendarDays },
    { key: 'tasks', labelKey: 'nav.tasks', url: '/m/tasks', icon: ListChecks },
    { key: 'shopping', labelKey: 'nav.shopping', url: '/m/shopping', icon: ShoppingCart },
    { key: 'spending', labelKey: 'nav.spending', url: '/m/spending', icon: Wallet },
    { key: 'notes', labelKey: 'nav.notes', url: '/m/notes', icon: StickyNote },
    { key: 'recipes', labelKey: 'nav.recipes', url: '/m/recipes', icon: ChefHat },
    { key: 'contacts', labelKey: 'nav.contacts', url: '/m/contacts', icon: Contact },
    { key: 'birthdays', labelKey: 'nav.birthdays', url: '/m/birthdays', icon: Cake },
    { key: 'inventory', labelKey: 'nav.inventory', url: '/m/inventory', icon: Package },
    { key: 'vehicles', labelKey: 'nav.vehicles', url: '/m/vehicles', icon: Car },
    { key: 'expense_import', labelKey: 'nav.expense_import', url: '/m/expense-import', icon: Import },
];
const SECONDARY: Item[] = [
    { key: 'members', labelKey: 'nav.members', url: '/family/members', icon: Users },
    { key: 'modules', labelKey: 'nav.modules', url: '/family/modules', icon: Blocks },
    { key: 'settings', labelKey: 'nav.settings', url: '/settings/profile', icon: Settings },
];

// The green "+" quick-add: each enabled module's top-level create, deep-linked with ?new=1 so the
// target index opens its create sheet on arrival. Order mirrors the nav.
const CREATE: Item[] = [
    { key: 'shopping', labelKey: 'create.shopping', url: '/m/shopping?new=1', icon: ShoppingCart },
    { key: 'tasks', labelKey: 'create.tasks', url: '/m/tasks?new=1', icon: ListChecks },
    { key: 'calendar', labelKey: 'create.calendar', url: '/m/calendar?new=1', icon: CalendarDays },
    { key: 'projects', labelKey: 'create.projects', url: '/m/projects?new=1', icon: FolderKanban },
    { key: 'spending', labelKey: 'create.spending', url: '/m/spending?new=1', icon: Wallet },
    // A note has no create sheet: POST straight to notes.store, which redirects into the new editor.
    { key: 'notes', labelKey: 'create.notes', url: '/m/notes', method: 'post', icon: StickyNote },
    // A recipe has a full editor (no sheet): deep-link straight to it.
    { key: 'recipes', labelKey: 'create.recipes', url: '/m/recipes/new', icon: ChefHat },
    // A vehicle has a full add form (no sheet): deep-link straight to it.
    { key: 'vehicles', labelKey: 'create.vehicles', url: '/m/vehicles/new', icon: Car },
];

const PINS_KEY = 'onsplan:navPins';
const MAX_PINS = 4;
// Default pins: this preferred order, intersected with what's actually available, first four.
const DEFAULT_ORDER = ['dashboard', 'projects', 'shopping', 'spending', 'members', 'modules', 'settings'];

export function MobileTabBar() {
    const page = usePage<{ modules?: { enabled?: string[] } }>();
    const t = useT();
    const enabled = page.props.modules?.enabled ?? [];

    // Everything reachable from the mobile nav, in display order.
    const allItems: Item[] = [HOME, ...MODULE_ITEMS.filter((m) => enabled.includes(m.key)), ...SECONDARY];
    const byKey = new Map(allItems.map((i) => [i.key, i]));

    const [pins, setPins] = useState<string[]>(() => {
        if (typeof window !== 'undefined') {
            try {
                const saved = JSON.parse(localStorage.getItem(PINS_KEY) || 'null');
                if (Array.isArray(saved)) return saved;
            } catch {
                /* fall through to default */
            }
        }
        return [];
    });
    const savePins = (next: string[]) => {
        setPins(next);
        try {
            localStorage.setItem(PINS_KEY, JSON.stringify(next));
        } catch {
            /* private mode: session-only */
        }
    };

    // Resolve the pinned bar items: saved pins (that still exist), else the default set.
    const savedPinned = pins.map((k) => byKey.get(k)).filter((i): i is Item => !!i);
    const pinned = (savedPinned.length ? savedPinned : DEFAULT_ORDER.map((k) => byKey.get(k)).filter((i): i is Item => !!i)).slice(0, MAX_PINS);
    const pinnedKeys = new Set(pinned.map((i) => i.key));

    const [moreOpen, setMoreOpen] = useState(false);
    const [addOpen, setAddOpen] = useState(false);
    const createItems = CREATE.filter((c) => enabled.includes(c.key));
    const [pending, setPending] = useState<string | null>(null);
    useEffect(() => {
        const offFinish = router.on('finish', (e) => {
            if (!e.detail.visit.prefetch) setPending(null);
        });
        return offFinish;
    }, []);

    const currentPath = pending ?? page.url;
    const isActive = (url: string) => currentPath === url || currentPath.startsWith(url + '/');
    // "More" lights up when the current page is a destination that isn't pinned to the bar.
    const moreActive = allItems.some((i) => !pinnedKeys.has(i.key) && isActive(i.url));

    const togglePin = (key: string) => {
        if (pins.length === 0) savePins([...pinned.map((i) => i.key)]); // materialize defaults before first edit
        if (pinnedKeys.has(key)) {
            savePins((pins.length ? pins : pinned.map((i) => i.key)).filter((k) => k !== key));
        } else if (pinned.length < MAX_PINS) {
            savePins([...(pins.length ? pins : pinned.map((i) => i.key)), key]);
        }
    };

    // Cells share the pill width evenly (flex-1) so the bar looks the same regardless of item count.
    // py-2 + 22px icon + 11px label = a ~50px tap target; 11px (not 10) is the smallest label that reads on a phone
    // and still fits "Inventory"/"Spending" in a fifth of the pill at 390px.
    const cell = 'flex min-w-0 flex-1 flex-col items-center gap-0.5 rounded-full py-2 text-[11px] font-medium transition-colors';

    return (
        <>
            {/* translateZ(0) promotes the bar to its own layer so iOS standalone PWAs keep it pinned to
                the viewport bottom instead of letting it scroll with the body (a known iOS fixed bug). */}
            <nav className="pointer-events-none fixed inset-x-0 bottom-0 z-50 flex [transform:translateZ(0)] items-center gap-2.5 px-4 pb-[calc(0.75rem+env(safe-area-inset-bottom))] md:hidden [@media(display-mode:standalone)]:pb-[max(0.5rem,calc(env(safe-area-inset-bottom)/2))]">
                {/* Pill fills the width; the + stays a fixed circle on the right. */}
                <div className="border-border/60 bg-surface-card/75 pointer-events-auto flex flex-1 items-center justify-around gap-0.5 rounded-full border p-1.5 shadow-[var(--shadow-fw-md)] backdrop-blur-xl">
                    {pinned.map((item) => {
                        const active = isActive(item.url);
                        const loading = pending === item.url;
                        return (
                            <Link
                                key={item.key}
                                href={item.url}
                                onClick={() => setPending(item.url)}
                                prefetch="mount"
                                cacheFor="30s"
                                className={`${cell} ${active ? 'bg-accent-subtle text-primary font-semibold' : 'text-text-muted'}`}
                            >
                                {loading ? <Loader2 className="size-5.5 animate-spin" /> : <item.icon className="size-5.5" />}
                                {t(item.labelKey)}
                            </Link>
                        );
                    })}
                    <button
                        type="button"
                        onClick={() => setMoreOpen(true)}
                        className={`${cell} ${moreActive ? 'bg-accent-subtle text-primary font-semibold' : 'text-text-muted'}`}
                    >
                        <MoreHorizontal className="size-5.5" />
                        {t('nav.more')}
                    </button>
                </div>

                {/* Detached green add button on the right (Slack-style separate action). Context-aware:
                    if the current page claimed it (useAddAction) run that; else open the quick-add sheet. */}
                <button
                    type="button"
                    onClick={() => {
                        if (!runAddAction()) setAddOpen(true);
                    }}
                    aria-label="Add"
                    className="bg-accent-strong text-accent-strong-foreground pointer-events-auto flex size-14 shrink-0 items-center justify-center rounded-full shadow-[var(--shadow-fw-md)] transition-transform active:scale-95"
                >
                    <Plus className="size-6" />
                </button>
            </nav>

            <ResponsiveModal open={addOpen} onOpenChange={setAddOpen} title={t('create.title')} description={t('create.desc')}>
                <div className="flex flex-col gap-1">
                    {createItems.length === 0 ? (
                        <Link
                            href="/family/modules"
                            onClick={() => setAddOpen(false)}
                            className="text-primary hover:bg-surface flex items-center gap-3 rounded-xl p-3 transition-colors"
                        >
                            <Blocks className="size-5.5" />
                            <span className="text-base font-medium">{t('create.turn_on')}</span>
                        </Link>
                    ) : (
                        createItems.map((item) => (
                            <Link
                                key={item.key}
                                href={item.url}
                                method={item.method ?? 'get'}
                                as={item.method === 'post' ? 'button' : undefined}
                                onClick={() => setAddOpen(false)}
                                className="text-primary hover:bg-surface flex w-full items-center gap-3 rounded-xl p-3 text-left transition-colors"
                            >
                                <span className="bg-tile text-tile-foreground flex size-10 items-center justify-center rounded-lg">
                                    <item.icon className="size-5.5" />
                                </span>
                                <span className="text-base font-medium">{t(item.labelKey)}</span>
                            </Link>
                        ))
                    )}
                </div>
            </ResponsiveModal>

            <ResponsiveModal open={moreOpen} onOpenChange={setMoreOpen} title={t('nav.more')} description={t('nav.more_desc')}>
                {/* Three columns of square cards: the card is the link, the pin sits in its corner as a sibling
                    button (a button inside a link is invalid HTML and would steal the tap). */}
                <div className="grid grid-cols-3 gap-2">
                    {allItems.map((item) => {
                        const isPinned = pinnedKeys.has(item.key);
                        const atMax = !isPinned && pinned.length >= MAX_PINS;
                        return (
                            <div key={item.key} className="relative aspect-square">
                                <Link
                                    href={item.url}
                                    onClick={() => {
                                        setMoreOpen(false);
                                        setPending(item.url);
                                    }}
                                    className={`flex size-full flex-col items-center justify-center gap-2 rounded-2xl border p-2 transition-colors ${
                                        isActive(item.url)
                                            ? 'border-primary bg-accent-subtle text-primary'
                                            : 'bg-surface-card text-primary hover:border-accent'
                                    }`}
                                >
                                    <span className="bg-tile text-tile-foreground flex size-11 items-center justify-center rounded-xl">
                                        <item.icon className="size-5.5" />
                                    </span>
                                    <span className="text-center text-sm leading-tight font-medium">{t(item.labelKey)}</span>
                                </Link>
                                <button
                                    type="button"
                                    onClick={() => togglePin(item.key)}
                                    disabled={atMax}
                                    aria-label={isPinned ? `Unpin ${t(item.labelKey)}` : `Pin ${t(item.labelKey)}`}
                                    aria-pressed={isPinned}
                                    className={`absolute top-0 right-0 flex size-10 items-center justify-center rounded-lg transition-colors ${
                                        isPinned ? 'text-primary' : atMax ? 'text-text-muted/40' : 'text-muted-foreground hover:bg-surface'
                                    }`}
                                >
                                    <Pin className={`size-4.5 ${isPinned ? 'fill-current' : ''}`} />
                                </button>
                            </div>
                        );
                    })}
                </div>
            </ResponsiveModal>
        </>
    );
}
