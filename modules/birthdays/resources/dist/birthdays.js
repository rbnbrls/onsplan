// Pre-built module frontend (shell runtime, no bundler, no second React). Extends the Contacts
// `person` primitive's slots and adds a home-screen widget:
//   - person.editor.fields : a day/month (+ optional year) birthday field in the contact editor
//   - person.row.badges    : a 🎂 badge (age + how soon) on the contact row
//   - dashboard.widgets    : an "Upcoming birthdays" card that fetches /m/birthdays/upcoming
const { React, registerSlot, Picker } = window.onsplan;
const h = React.createElement;

// Editor field: day + month, year optional (age only shows once a year is known). Persists via
// onPatch (the contacts endpoint); the backend person.saved hook stores it. Native inputs on purpose
// (mobile number keypad). Sends the trio only once day+month are both set; clearing the day clears
// the birthday.
function BirthdateField(props) {
    const person = props.person || {};
    const onPatch = props.onPatch;
    const [day, setDay] = React.useState(person.birthDay ? String(person.birthDay) : '');
    const [month, setMonth] = React.useState(person.birthMonth ? String(person.birthMonth) : '');
    const [year, setYear] = React.useState(person.birthYear ? String(person.birthYear) : '');
    const months = Array.from({ length: 12 }, (_, i) => new Date(2024, i, 1).toLocaleString(undefined, { month: 'short' }));

    function push(d, m, y) {
        if (!onPatch) return;
        if (!d) return onPatch({ birth_month: '', birth_day: '', birth_year: '' }); // empty clears it
        if (d && m) onPatch({ birth_day: Number(d), birth_month: Number(m), birth_year: y ? Number(y) : null });
    }

    // h-[42px] lines the day/year inputs up with the month Picker (the app's shared dropdown, exposed on
    // window.onsplan); the row aligns to the top so they stay put while the month list is open.
    const cls = 'border-border text-primary h-[42px] rounded-lg border bg-transparent px-2 text-base md:text-sm';
    return h(
        'div',
        { className: 'grid gap-2', 'data-testid': 'birthdate-field' },
        h('label', { className: 'text-muted-foreground text-xs font-semibold tracking-widest uppercase' }, 'Birthday'),
        h(
            'div',
            { className: 'flex items-start gap-1.5' },
            h('input', { type: 'number', inputMode: 'numeric', min: 1, max: 31, placeholder: 'Day', 'aria-label': 'Day', value: day, className: cls + ' w-16',
                onChange: (e) => { setDay(e.target.value); push(e.target.value, month, year); } }),
            h('div', { className: 'w-32 shrink-0' },
                h(Picker, {
                    ariaLabel: 'Month',
                    placeholder: 'Month',
                    fallbackIcon: () => null, // no icon set to import here; the month name is enough
                    options: months.map((m, i) => ({ value: String(i + 1), label: m })),
                    selected: month ? [month] : [],
                    onChange: (next) => { const m = next[0] || ''; setMonth(m); push(day, m, year); },
                }),
            ),
            h('input', { type: 'number', inputMode: 'numeric', min: 1900, max: new Date().getFullYear(), placeholder: 'Year', 'aria-label': 'Year (optional)', value: year, className: cls + ' w-28',
                onChange: (e) => { setYear(e.target.value); push(day, month, e.target.value); } }),
        ),
        person.age != null ? h('span', { className: 'text-muted-foreground text-xs' }, 'Age ' + person.age) : null,
    );
}

// Row badge: 🎂 with current age and how soon the next birthday is.
function BirthdayBadge(props) {
    const person = props.person || {};
    if (!person.birthMonth) return null;
    const days = person.daysUntilBirthday;
    const soon = days === 0 ? 'today' : days != null ? 'in ' + days + 'd' : '';
    return h(
        'span',
        {
            'data-testid': 'birthday-badge',
            className:
                'shrink-0 inline-flex items-center gap-1 rounded-full border border-border px-2 py-0.5 text-[11px] text-muted-foreground',
            title: 'Next birthday ' + (person.nextBirthday || ''),
        },
        h('span', null, '🎂'),
        person.age != null ? h('span', null, person.age) : null,
        soon ? h('span', { className: 'opacity-70' }, soon) : null,
    );
}

function whenLabel(b) {
    if (b.daysUntil === 0) return 'Today!';
    if (b.daysUntil === 1) return 'Tomorrow';
    return 'in ' + b.daysUntil + ' days · ' + b.date;
}

// Home widget: upcoming birthdays for the family. Fetches its own data (like every module widget),
// renders nothing while loading or when there is nothing coming up.
function BirthdaysWidget() {
    const [items, setItems] = React.useState(null);
    const [total, setTotal] = React.useState(0);
    React.useEffect(function () {
        fetch('/m/birthdays/upcoming', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : { birthdays: [] }))
            .then((d) => { setItems(d.birthdays || []); setTotal(d.total || 0); })
            .catch(() => setItems([]));
    }, []);

    if (items === null || items.length === 0) return null;

    // The feed is capped server-side; "See all" leads to the full Birthdays page. Plain anchor
    // (the shell runtime exposes no Inertia Link), so it's a full navigation — fine for a link.
    return h(
        'section',
        { className: 'flex flex-col gap-3', 'data-testid': 'birthdays-widget' },
        h(
            'div',
            { className: 'flex items-baseline justify-between px-1' },
            h('h2', { className: 'font-heading text-primary text-lg' }, 'Upcoming birthdays'),
            h('a', { href: '/m/birthdays', className: 'text-muted-foreground text-sm underline-offset-2 hover:underline' },
                total > items.length ? 'See all (' + total + ')' : 'See all'),
        ),
        h(
            'div',
            { className: 'bg-surface-card flex flex-col divide-y rounded-xl border' },
            items.map((b) =>
                h(
                    'div',
                    { key: b.personId, className: 'flex items-center gap-3 px-3 py-2.5' },
                    h('span', { className: 'text-lg' }, '🎂'),
                    h(
                        'span',
                        { className: 'min-w-0 flex-1' },
                        h('span', { className: 'text-primary block truncate text-[15px]' }, b.name),
                        h('span', { className: 'text-muted-foreground block text-xs' }, whenLabel(b)),
                    ),
                    b.turning != null ? h('span', { className: 'text-muted-foreground shrink-0 text-xs' }, 'turns ' + b.turning) : null,
                ),
            ),
        ),
    );
}

registerSlot('person.editor.fields', BirthdateField);
registerSlot('person.row.badges', BirthdayBadge);
registerSlot('dashboard.widgets', BirthdaysWidget);
