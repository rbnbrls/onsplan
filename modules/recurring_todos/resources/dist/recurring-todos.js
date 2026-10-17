// Pre-built module frontend (shell runtime, no bundler, no second React). Registers into the task
// edit screen's slots: an interval picker in the editor (task.editor.fields) and a repeat badge on
// the row (task.row.badges). `ctx` is spread as props by <Slot>; onPatch persists via the tasks
// endpoint (the backend `task.saved` hook stores it).
const { React, registerSlot } = window.onsplan;
const h = React.createElement;

const OPTIONS = [
    ['none', 'None'],
    ['daily', 'Daily'],
    ['weekly', 'Weekly'],
    ['monthly', 'Monthly'],
    ['yearly', 'Yearly'],
];
const LABEL = { daily: 'Daily', weekly: 'Weekly', monthly: 'Monthly', yearly: 'Yearly' };

// Editor field: pick how often the task repeats. Pairs with the built-in date field above it.
function RecurrenceField(props) {
    const task = props.task || {};
    const onPatch = props.onPatch;
    const [val, setVal] = React.useState(task.recurrence || 'none');

    function pick(v) {
        setVal(v);
        if (onPatch) onPatch({ recurrence: v });
    }

    return h(
        'div',
        { className: 'grid gap-2', 'data-testid': 'recurrence-field' },
        h(
            'label',
            { className: 'text-muted-foreground text-xs font-semibold tracking-widest uppercase' },
            'Repeat',
        ),
        h(
            'div',
            { className: 'flex flex-wrap gap-2' },
            OPTIONS.map(([v, label]) =>
                h(
                    'button',
                    {
                        key: v,
                        type: 'button',
                        onClick: () => pick(v),
                        'data-recur': v,
                        className:
                            'rounded-full border px-3 py-1.5 text-sm transition-colors ' +
                            (val === v
                                ? 'border-primary bg-accent-strong text-accent-strong-foreground'
                                : 'border-border text-primary'),
                    },
                    label,
                ),
            ),
        ),
    );
}

// Row badge: a repeat marker with the interval, when the task recurs.
function RecurrenceBadge(props) {
    const task = props.task || {};
    if (!task.recurrence || task.recurrence === 'none') return null;
    return h(
        'span',
        {
            'data-testid': 'recurrence-badge',
            className:
                'shrink-0 inline-flex items-center gap-1 rounded-full border border-border px-2 py-0.5 text-[11px] text-muted-foreground',
            title: 'Repeats ' + task.recurrence,
        },
        h('span', null, '↻'),
        h('span', null, LABEL[task.recurrence] || 'Repeat'),
    );
}

registerSlot('task.editor.fields', RecurrenceField);
registerSlot('task.row.badges', RecurrenceBadge);
