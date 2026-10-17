// Pre-built module frontend (shell runtime, no bundler, no second React). Adds a home-screen widget:
//   - dashboard.widgets : an "Inventory" card surfacing what's running low / out, with quick links.
// It fetches its own data from /m/inventory/widget (gated by module:inventory on the server) and
// renders nothing while loading, when the module is off (the feed 404s), or when nothing needs
// attention — so a healthy inventory adds no clutter to the dashboard. Mirrors the Birthdays widget.
const { React, registerSlot } = window.onsplan;
const h = React.createElement;

// A short, human label + colour token per exception state. Colours match the app's state tokens
// (var(--warning) amber for low, var(--destructive) red for empty/expired), so the widget tracks the skin.
const STATE_META = {
    low: { label: 'Low', color: 'var(--warning)' },
    empty: { label: 'Empty', color: 'var(--destructive)' },
    expired: { label: 'Expired', color: 'var(--destructive)' },
};

// One compact count tile in the widget header (e.g. "3 · Low").
function stat(value, label, color) {
    return h(
        'div',
        { key: label, className: 'flex flex-col' },
        h('span', { className: 'text-lg font-semibold tabular-nums', style: color ? { color: value > 0 ? color : 'var(--primary)' } : null }, String(value)),
        h('span', { className: 'text-muted-foreground text-[11px] font-semibold tracking-widest uppercase' }, label),
    );
}

function InventoryWidget() {
    const [data, setData] = React.useState(null);
    React.useEffect(function () {
        fetch('/m/inventory/widget', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : null))
            .then((d) => setData(d))
            .catch(() => setData(null));
    }, []);

    // Hide while loading, when the module is off (null), or when nothing needs attention (no exceptions).
    if (!data || !data.items || data.items.length === 0) return null;

    return h(
        'section',
        { className: 'flex flex-col gap-3', 'data-testid': 'inventory-widget' },
        h(
            'div',
            { className: 'flex items-center justify-between px-1' },
            h('h2', { className: 'font-heading text-primary text-lg' }, 'Inventory'),
            h('a', { href: '/m/inventory', className: 'text-primary text-xs font-medium' }, 'View inventory'),
        ),
        h(
            'div',
            { className: 'bg-surface-card flex flex-col gap-3 rounded-xl border p-4' },
            // Headline counts: items / running low / empty.
            h(
                'div',
                { className: 'flex items-center gap-6' },
                stat(data.total, 'Items'),
                stat(data.low, 'Low', 'var(--warning)'),
                stat(data.empty, 'Empty', 'var(--destructive)'),
            ),
            // A few most-urgent exception items (never the whole inventory).
            h(
                'div',
                { className: 'flex flex-col divide-y' },
                data.items.map((it) => {
                    const meta = STATE_META[it.state] || { label: it.state, color: 'var(--muted-foreground)' };
                    return h(
                        'a',
                        { key: it.id, href: '/m/inventory/items/' + it.id, className: 'flex items-center gap-3 py-2' },
                        h('span', { className: 'size-2 shrink-0 rounded-full', style: { background: meta.color } }),
                        h('span', { className: 'text-primary min-w-0 flex-1 truncate text-sm' }, it.name),
                        h('span', { className: 'text-muted-foreground shrink-0 text-xs' }, meta.label),
                    );
                }),
            ),
            // Action: jump straight to the replenishment screen ("Add all to Shopping" lives there).
            h(
                'a',
                {
                    href: '/m/inventory/replenishment',
                    className: 'text-primary flex items-center justify-center rounded-lg border py-2 text-sm font-medium',
                },
                'Add to Shopping',
            ),
        ),
    );
}

registerSlot('dashboard.widgets', InventoryWidget);
