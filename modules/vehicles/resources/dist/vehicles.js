// Pre-built module frontend (shell runtime, no bundler, no second React). Adds a home-screen widget:
//   - dashboard.widgets : a "Vehicles" card, one compact line per car that needs attention (name /
//     mileage / status) plus the exceptions/next-ups (maintenance due, expiring documents).
// It fetches its own data from /m/vehicles/widget (gated by module:vehicles on the server) and renders
// nothing while loading, when the module is off (the feed 404s), or when NO car needs attention (the
// server omits healthy cars) — so a healthy fleet adds no clutter to the dashboard. Mirrors the
// Inventory / Birthdays widgets.
const { React, registerSlot } = window.onsplan;
const h = React.createElement;

// Icon glyph + colour token per exception kind. Colours track the app's state tokens (amber warning,
// forest primary), so the widget follows the active skin.
const KIND_META = {
    maintenance: { glyph: '🔧', color: 'var(--warning)' },
    document: { glyph: '📄', color: 'var(--primary)' },
};

// One car block: the header line (name / mileage / N need attention) + a few exception rows.
function carBlock(car) {
    return h(
        'div',
        { key: car.id, className: 'flex flex-col gap-2' },
        h(
            'a',
            { href: '/m/vehicles/' + car.id, className: 'flex items-baseline justify-between gap-3' },
            h(
                'span',
                { className: 'min-w-0 flex items-baseline gap-2' },
                h('span', { className: 'text-primary truncate text-sm font-medium' }, car.name),
                h('span', { className: 'text-muted-foreground shrink-0 text-xs tabular-nums' }, car.mileageText),
            ),
            h('span', { className: 'text-muted-foreground shrink-0 text-[11px] font-semibold tracking-wide uppercase' }, car.attention + ' to check'),
        ),
        h(
            'div',
            { className: 'flex flex-col divide-y' },
            (car.items || []).map((it, i) => {
                const meta = KIND_META[it.kind] || { glyph: '•', color: 'var(--muted-foreground)' };
                return h(
                    'div',
                    { key: i, className: 'flex items-center gap-2.5 py-1.5' },
                    h('span', { className: 'shrink-0 text-sm', style: { color: meta.color } }, meta.glyph),
                    h('span', { className: 'text-primary min-w-0 flex-1 truncate text-sm' }, it.label),
                    it.detail ? h('span', { className: 'text-muted-foreground shrink-0 text-xs tabular-nums' }, it.detail) : null,
                );
            }),
        ),
    );
}

function VehiclesWidget() {
    const [data, setData] = React.useState(null);
    React.useEffect(function () {
        fetch('/m/vehicles/widget', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : null))
            .then((d) => setData(d))
            .catch(() => setData(null));
    }, []);

    // Hide while loading, when the module is off (null), or when no car needs attention (empty list).
    if (!data || !data.cars || data.cars.length === 0) return null;

    return h(
        'section',
        { className: 'flex flex-col gap-3', 'data-testid': 'vehicles-widget' },
        h(
            'div',
            { className: 'flex items-center justify-between px-1' },
            h('h2', { className: 'font-heading text-primary text-lg' }, 'Vehicles'),
            h('a', { href: '/m/vehicles', className: 'text-primary text-xs font-medium' }, 'View cars'),
        ),
        h('div', { className: 'bg-surface-card flex flex-col gap-4 rounded-xl border p-4' }, data.cars.map(carBlock)),
    );
}

registerSlot('dashboard.widgets', VehiclesWidget);
