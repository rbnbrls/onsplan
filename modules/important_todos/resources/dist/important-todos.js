// Pre-built module frontend: uses the shell runtime (window.onsplan), no bundler, no second
// React. It registers into two slots the task edit
// screen exposes (resources/js/components/tasks/task-tree.tsx):
//   - task.editor.fields : an "Important" toggle in the edit drawer
//   - task.row.badges    : a star on important rows
// Both receive a `ctx` the primitive spreads as props (task, and for the editor an onPatch fn).
const { React, registerSlot } = window.onsplan;
const h = React.createElement;

// Editor field: a toggle bound to the task's `important` flag. It persists through `onPatch`, which
// the tasks module wires to its own task endpoint — the primitive keeps ownership of the router, the
// module only says "patch this field". The backend `task.saved` hook stores it.
function ImportantField(props) {
    const task = props.task || {};
    const onPatch = props.onPatch;
    const [on, setOn] = React.useState(!!task.important);

    function toggle() {
        const next = !on;
        setOn(next); // optimistic
        if (onPatch) onPatch({ important: next });
    }

    return h(
        'button',
        {
            type: 'button',
            onClick: toggle,
            'data-testid': 'important-toggle',
            className:
                'flex items-center justify-between rounded-lg border px-3 py-2 text-sm transition-colors ' +
                (on ? 'border-primary text-primary' : 'border-border text-muted-foreground'),
        },
        h('span', { className: 'font-medium' }, 'Important'),
        h('span', { className: 'text-xs' }, on ? '★ On' : 'Off'),
    );
}

// Row badge: a star when the task is important. Read-only display.
function ImportantBadge(props) {
    const task = props.task || {};
    if (!task.important) return null;
    return h(
        'span',
        { 'data-testid': 'important-badge', className: 'shrink-0 text-amber-500', title: 'Important' },
        '★',
    );
}

registerSlot('task.editor.fields', ImportantField);
registerSlot('task.row.badges', ImportantBadge);
