import {
    closestCenter,
    DndContext,
    type DragEndEvent,
    type DragMoveEvent,
    DragOverlay,
    type DragStartEvent,
    KeyboardSensor,
    PointerSensor,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import { arrayMove, SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { router } from '@inertiajs/react';
import { Calendar, Check, Plus, Trash2, UserCircle2 } from 'lucide-react';
import { type Ref, useEffect, useMemo, useRef, useState } from 'react';

import { MemberAvatar } from '@/components/member-avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useOptimistic } from '@/hooks/use-optimistic';
import { useT } from '@/lib/i18n';
import { Slot } from '@/lib/modules';
import { dueLabel } from '@/lib/tasks';
import { buildTree, type FlatTask, flatten, getProjection, INDENT, removeChildrenOf } from './tree-utils';

export type TaskMember = { id: number; name: string };
export type SubTask = { id: number; title: string; note: string | null; done: boolean; dueDate: string | null; assignedTo: number | null };
export type TaskNode = SubTask & { subtasks: SubTask[] };

// Shared task list, used by the tasks module and the project detail's unified Tasks section. The two
// contexts differ only in their endpoints: `addUrl` (POST {title, parent_id?}), `taskUrl(id)` (PATCH
// edit/toggle, DELETE), and `reorderUrl` (POST {order:[{id,parent_id}]}).
//
// Gestures (Apple Reminders style): tap the circle to toggle, tap the title to edit, swipe a row left
// to delete, and drag the grip to reorder — dragging right nests a task under the one above (one level
// only), dragging left un-nests it.
type Props = {
    tasks: TaskNode[];
    members: TaskMember[];
    canEdit: boolean;
    addUrl: string;
    taskUrl: (id: number) => string;
    reorderUrl: string;
    emptyHint?: string;
    // Lets the page focus the "add a task" input from outside (the nav's context-aware "+").
    addInputRef?: Ref<HTMLInputElement>;
};

function memberName(members: TaskMember[], id: number | null) {
    return members.find((m) => m.id === id)?.name ?? null;
}

function AssigneeDot({ members, id }: { members: TaskMember[]; id: number | null }) {
    const name = memberName(members, id);
    if (!name) return null;
    return <MemberAvatar userId={id} name={name} className="size-5 text-[10px]" />;
}

export function TaskTree({ tasks, members, canEdit, addUrl, taskUrl, reorderUrl, emptyHint, addInputRef }: Props) {
    const t = useT();
    const inputRef = useRef<HTMLInputElement>(null);
    const doneOpt = useOptimistic<boolean>();
    // Optimistic removal: a deleted task (and its sub-tasks) disappears immediately, reappearing only
    // if the DELETE fails. Overlay on the local mirror rather than mutating it, so a failed delete
    // needs no re-sync (the server key is unchanged, so the mirror would not refresh on its own).
    const removed = useOptimistic<boolean>();
    const [newTitle, setNewTitle] = useState('');
    const [editing, setEditing] = useState<SubTask | null>(null);
    const [openSwipe, setOpenSwipe] = useState<number | null>(null); // row whose delete action is revealed

    // Local mirror of the server tree so drags feel instant; re-sync whenever the server sends new props.
    // The key is the WHOLE payload, not a subset: rows and the edit drawer both read from this mirror, so
    // a field missing from the key never reaches the screen. It used to be ids + `done` only, so an edited
    // title/note/date/assignee (or a module field like `important`) stayed stale until a full reload; the
    // save looked lost, and re-opening the drawer sent the old values back, reverting it.
    const [items, setItems] = useState<FlatTask[]>(() => flatten(tasks));
    const serverKey = useMemo(() => JSON.stringify(tasks), [tasks]);
    const lastKey = useRef(serverKey);
    useEffect(() => {
        if (lastKey.current !== serverKey) {
            lastKey.current = serverKey;
            setItems(flatten(tasks));
        }
    }, [serverKey, tasks]);

    const [activeId, setActiveId] = useState<number | null>(null);
    const [overId, setOverId] = useState<number | null>(null);
    const [offsetLeft, setOffsetLeft] = useState(0);

    // No drag handle: press-and-hold anywhere on a row to drag it (Apple Reminders style). The delay
    // lets a quick tap toggle/edit and a fast horizontal swipe reveal delete without starting a drag.
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { delay: 200, tolerance: 6 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
    );

    // While dragging a parent, hide its sub-tasks so the parent moves as one unit.
    const visibleItems = items.filter((i) => !removed.get(i.id, false) && !(i.parentId != null && removed.get(i.parentId, false)));
    // ponytail: no useMemo here, the overlay object changes identity each render anyway; tens of rows.
    const flattened = activeId != null ? removeChildrenOf(visibleItems, [activeId]) : visibleItems;
    const sortedIds = useMemo(() => flattened.map((i) => i.id), [flattened]);
    const projection = activeId != null && overId != null ? getProjection(flattened, activeId, overId, offsetLeft, INDENT) : null;
    const activeItem = activeId != null ? flattened.find((i) => i.id === activeId) : null;

    const isDone = (t: SubTask) => doneOpt.get(t.id, t.done);
    const toggle = (t: SubTask) => {
        if (!canEdit) return;
        doneOpt.set(t.id, !isDone(t));
        router.patch(
            taskUrl(t.id),
            {},
            { preserveScroll: true, preserveState: true, onSuccess: () => doneOpt.clear(t.id), onError: () => doneOpt.clear(t.id) },
        );
    };
    const addTask = () => {
        if (!newTitle.trim()) {
            // A tap on the prominent + with an empty field should give immediate feedback
            // instead of appearing to do nothing.
            window.setTimeout(() => inputRef.current?.focus(), 0);
            return;
        }
        router.post(addUrl, { title: newTitle.trim() }, { preserveScroll: true, onSuccess: () => setNewTitle('') });
    };
    const del = (id: number) => {
        setOpenSwipe(null);
        removed.set(id, true);
        router.delete(taskUrl(id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => removed.clear(id),
            onError: () => removed.clear(id),
        });
    };

    function resetDrag() {
        setActiveId(null);
        setOverId(null);
        setOffsetLeft(0);
    }
    function onDragStart({ active }: DragStartEvent) {
        setOpenSwipe(null);
        setActiveId(active.id as number);
        setOverId(active.id as number);
    }
    function onDragMove({ delta }: DragMoveEvent) {
        setOffsetLeft(delta.x);
    }
    function onDragOver({ over }: { over: { id: string | number } | null }) {
        setOverId((over?.id as number) ?? null);
    }
    function onDragEnd({ active, over }: DragEndEvent) {
        const proj = over ? getProjection(flattened, active.id as number, over.id as number, offsetLeft, INDENT) : null;
        resetDrag();
        if (!proj || !over) return;

        const activeIndex = flattened.findIndex((i) => i.id === active.id);
        const overIndex = flattened.findIndex((i) => i.id === over.id);
        const moved = arrayMove(flattened, activeIndex, overIndex).map((i) =>
            i.id === active.id ? { ...i, depth: proj.depth, parentId: proj.parentId } : i,
        );
        // Re-attach the dragged parent's sub-tasks right after it (they were hidden during the drag).
        const children = items.filter((i) => i.parentId === active.id).map((c) => ({ ...c, depth: 1, parentId: active.id as number }));
        const result: FlatTask[] = [];
        for (const it of moved) {
            result.push(it);
            if (it.id === active.id && children.length) result.push(...children);
        }

        setItems(result);
        router.post(reorderUrl, { order: result.map((i) => ({ id: i.id, parent_id: i.parentId })) }, { preserveScroll: true, preserveState: true });
    }

    const tree = buildTree(visibleItems); // for the empty check + counts if needed

    return (
        <div className="flex flex-col gap-2">
            {tree.length === 0 && <p className="text-muted-foreground py-3 text-sm">{emptyHint ?? t('tasks.empty_tasks_short')}</p>}

            <DndContext
                sensors={sensors}
                collisionDetection={closestCenter}
                onDragStart={onDragStart}
                onDragMove={onDragMove}
                onDragOver={onDragOver}
                onDragEnd={onDragEnd}
                onDragCancel={resetDrag}
            >
                <SortableContext items={sortedIds} strategy={verticalListSortingStrategy}>
                    {flattened.map((item) => (
                        <SortableRow
                            key={item.id}
                            item={item}
                            depth={item.id === activeId && projection ? projection.depth : item.depth}
                            members={members}
                            canEdit={canEdit}
                            done={isDone(item)}
                            onToggle={() => toggle(item)}
                            onEdit={() => setEditing(item)}
                            onDelete={() => del(item.id)}
                            swipeOpen={openSwipe === item.id}
                            onSwipeOpen={(v) => setOpenSwipe(v ? item.id : null)}
                        />
                    ))}
                </SortableContext>
                <DragOverlay>
                    {activeItem ? (
                        <div className="bg-surface-card flex items-center gap-2 rounded-lg border px-3 py-2.5 shadow-lg">
                            <span className="border-border size-5 shrink-0 rounded-full border" />
                            <span className="text-primary text-[15px]">{activeItem.title}</span>
                        </div>
                    ) : null}
                </DragOverlay>
            </DndContext>

            {canEdit && (
                <div className="mt-1 flex items-center gap-2">
                    <Input
                        ref={(node) => {
                            inputRef.current = node;
                            if (typeof addInputRef === 'function') addInputRef(node);
                            else if (addInputRef) addInputRef.current = node;
                        }}
                        value={newTitle}
                        onChange={(e) => setNewTitle(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && addTask()}
                        placeholder={t('tasks.add_task_placeholder')}
                        className="text-base md:text-sm"
                    />
                    <Button
                        size="icon"
                        className="shrink-0 rounded-lg"
                        onMouseDown={(event) => {
                            if (!newTitle.trim()) event.preventDefault();
                        }}
                        onClick={addTask}
                        aria-label={t('tasks.add_task')}
                    >
                        <Plus className="size-5" />
                    </Button>
                </div>
            )}

            {editing && (
                <EditTaskModal task={editing} members={members} taskUrl={taskUrl} onClose={() => setEditing(null)} onDelete={() => del(editing.id)} />
            )}
        </div>
    );
}

function SortableRow({
    item,
    depth,
    members,
    canEdit,
    done,
    onToggle,
    onEdit,
    onDelete,
    swipeOpen,
    onSwipeOpen,
}: {
    item: FlatTask;
    depth: number;
    members: TaskMember[];
    canEdit: boolean;
    done: boolean;
    onToggle: () => void;
    onEdit: () => void;
    onDelete: () => void;
    swipeOpen: boolean;
    onSwipeOpen: (v: boolean) => void;
}) {
    const t = useT();
    // Only the drag `listeners` are spread (not `attributes`): attributes would put role="button" on the
    // row, nesting the real buttons (toggle/title) inside a button. Keyboard-drag a11y is traded away.
    const { listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: item.id });
    const due = dueLabel(item.dueDate);
    const sub = depth > 0;

    // Swipe-left to reveal Delete, on the inner content row. A press-and-hold instead starts a drag
    // (dnd-kit's delay sensor owns the whole row), and while dragging the swipe is suppressed.
    const [swipeX, setSwipeX] = useState(0);
    const start = useRef<{ x: number; y: number } | null>(null);
    const moved = useRef(false); // did this gesture swipe? then the trailing click shouldn't open edit
    const REVEAL = 76;
    useEffect(() => {
        if (!swipeOpen && swipeX !== 0) setSwipeX(0);
    }, [swipeOpen]); // eslint-disable-line react-hooks/exhaustive-deps
    useEffect(() => {
        if (isDragging) setSwipeX(0);
    }, [isDragging]);

    const onPointerDown = (e: React.PointerEvent) => {
        if (!canEdit) return;
        start.current = { x: e.clientX, y: e.clientY };
        moved.current = false;
    };
    const onPointerMove = (e: React.PointerEvent) => {
        const s = start.current;
        if (!s || isDragging) return;
        const dx = e.clientX - s.x;
        const dy = e.clientY - s.y;
        if (Math.abs(dx) < Math.abs(dy)) return; // vertical => let it scroll / start a drag
        if (Math.abs(dx) > 4) moved.current = true;
        const base = swipeOpen ? -REVEAL : 0;
        setSwipeX(Math.max(-REVEAL, Math.min(0, base + dx)));
    };
    // Tap anywhere on the row (except the toggle circle) opens the edit drawer — unless the gesture was
    // a swipe or the delete action is showing.
    const handleEdit = () => {
        if (moved.current || swipeOpen) {
            onSwipeOpen(false);
            return;
        }
        onEdit();
    };
    const onPointerUp = () => {
        if (!start.current) return;
        start.current = null;
        if (swipeX < -REVEAL / 2) {
            setSwipeX(-REVEAL);
            onSwipeOpen(true);
        } else {
            setSwipeX(0);
            onSwipeOpen(false);
        }
    };

    return (
        <div
            ref={setNodeRef}
            {...(canEdit ? listeners : {})}
            style={{ transform: CSS.Translate.toString(transform), transition, paddingLeft: sub ? INDENT : 0 }}
            className={`relative ${isDragging ? 'z-10 opacity-40' : ''}`}
        >
            {/* Delete action revealed behind the row on swipe. */}
            <button
                type="button"
                onClick={onDelete}
                aria-label={t('tasks.delete_task')}
                className="bg-destructive text-destructive-foreground absolute inset-y-0 right-0 flex w-[76px] items-center justify-center rounded-xl"
            >
                <Trash2 className="size-4.5" />
            </button>

            <div
                onPointerDown={onPointerDown}
                onPointerMove={onPointerMove}
                onPointerUp={onPointerUp}
                onPointerCancel={onPointerUp}
                style={{ transform: `translateX(${swipeX}px)` }}
                className={`bg-surface-card relative flex items-center gap-2.5 rounded-xl border py-2.5 ${sub ? 'px-2' : 'px-3'}`}
            >
                <button
                    type="button"
                    onClick={onToggle}
                    aria-label={done ? t('tasks.mark_not_done') : t('tasks.mark_done')}
                    className={`flex ${sub ? 'size-5' : 'size-6'} shrink-0 items-center justify-center rounded-full border transition-colors ${
                        done ? 'border-primary bg-accent-strong text-accent-strong-foreground' : 'border-border'
                    }`}
                >
                    {done && <Check className={sub ? 'size-3' : 'size-3.5'} />}
                </button>
                {/* The rest of the row is one tap target that opens the edit drawer. */}
                <button type="button" onClick={handleEdit} className="flex min-w-0 flex-1 items-center gap-2.5 text-left">
                    <span className="min-w-0 flex-1">
                        {/* Wrap long titles instead of truncating so a long to-do never widens the row past the screen. */}
                        <span
                            className={`block break-words ${sub ? 'text-sm' : 'text-[15px]'} ${done ? 'text-muted-foreground line-through' : 'text-primary'}`}
                        >
                            {item.title}
                        </span>
                        {item.note && <span className="text-muted-foreground block text-xs break-words">{item.note}</span>}
                    </span>
                    {due && <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium ${due.tone}`}>{due.label}</span>}
                    {/* Extension point: a module can render read-only badges for this task (e.g. an
                        "important" star). Renders nothing when no module is registered. */}
                    <Slot name="task.row.badges" ctx={{ task: item }} />
                    <AssigneeDot members={members} id={item.assignedTo} />
                </button>
            </div>
        </div>
    );
}

// Edit a single task: title, note, due date, assignee, plus a Delete. Save PATCHes the task endpoint.
function EditTaskModal({
    task,
    members,
    taskUrl,
    onClose,
    onDelete,
}: {
    task: SubTask;
    members: TaskMember[];
    taskUrl: (id: number) => string;
    onClose: () => void;
    onDelete: () => void;
}) {
    const t = useT();
    const [title, setTitle] = useState(task.title);
    const [note, setNote] = useState(task.note ?? '');
    const [due, setDue] = useState(task.dueDate ?? '');
    const [assigned, setAssigned] = useState<number | null>(task.assignedTo);

    // Once the task is deleted it no longer exists on the server, so any further PATCH to its endpoint
    // 404s (route-model binding can't resolve it) and Inertia paints the branded "Nothing here" page.
    // This guard makes both the debounced auto-save and the on-close flush no-ops after a delete.
    const deleted = useRef(false);

    const patch = () =>
        router.patch(
            taskUrl(task.id),
            { title: title.trim() || task.title, note: note.trim() || null, due_date: due || null, assigned_to: assigned },
            { preserveScroll: true, preserveState: true },
        );
    // Auto-save: debounce field changes; skip the initial mount values; flush on close (below).
    const first = useRef(true);
    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        if (deleted.current || !title.trim()) return;
        const t = setTimeout(patch, 500);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [title, note, due, assigned]);

    // Delete then close the drawer ourselves — leaving it open would let the on-close flush below fire a
    // PATCH against the just-deleted task (the 404 root cause). `deleted` also blocks the auto-save flush.
    const handleDelete = () => {
        deleted.current = true;
        onDelete();
        onClose();
    };

    return (
        <ResponsiveModal
            open
            onOpenChange={(v) => {
                if (v) return;
                if (!deleted.current && title.trim()) patch(); // flush the last edit
                onClose();
            }}
            title={t('tasks.edit_task')}
        >
            <div className="flex flex-col gap-4">
                <div className="grid gap-2">
                    <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('tasks.field_title')}</Label>
                    <Input autoFocus value={title} onChange={(e) => setTitle(e.target.value)} className="text-base md:text-sm" />
                </div>
                <div className="grid gap-2">
                    <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('tasks.field_note')}</Label>
                    <Input
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        placeholder={t('tasks.optional_placeholder')}
                        className="text-base md:text-sm"
                    />
                </div>
                <div className="grid gap-2">
                    <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('tasks.due_date')}</Label>
                    <div className="border-border flex h-9 w-full items-center gap-2 rounded-lg border px-3">
                        <Calendar className="text-muted-foreground size-4 shrink-0" />
                        <input
                            type="date"
                            value={due}
                            onChange={(e) => setDue(e.target.value)}
                            className="text-primary w-full min-w-0 bg-transparent text-sm outline-none"
                        />
                        {due && (
                            <button type="button" onClick={() => setDue('')} className="text-muted-foreground hover:text-primary text-xs">
                                {t('tasks.clear')}
                            </button>
                        )}
                    </div>
                </div>
                <div className="grid gap-2">
                    <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('tasks.assign_to')}</Label>
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() => setAssigned(null)}
                            className={`flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm transition-colors ${
                                assigned === null ? 'border-primary bg-accent-strong text-accent-strong-foreground' : 'border-border text-primary'
                            }`}
                        >
                            <UserCircle2 className="size-4" /> {t('tasks.anyone')}
                        </button>
                        {members.map((m) => (
                            <button
                                key={m.id}
                                type="button"
                                onClick={() => setAssigned(m.id)}
                                className={`rounded-full border px-3 py-1.5 text-sm transition-colors ${
                                    assigned === m.id ? 'border-primary bg-accent-strong text-accent-strong-foreground' : 'border-border text-primary'
                                }`}
                            >
                                {m.name}
                            </button>
                        ))}
                    </div>
                </div>
                {/* Extension point: a module can inject extra editor fields for this task (e.g. an
                    "important" toggle). `onPatch` lets it persist through the same task endpoint —
                    the primitive keeps ownership of the router. Renders nothing with no module. */}
                <Slot
                    name="task.editor.fields"
                    ctx={{
                        task,
                        onPatch: (data: Record<string, string | number | boolean | null>) =>
                            router.patch(taskUrl(task.id), data, { preserveScroll: true, preserveState: true }),
                    }}
                />
                <button
                    type="button"
                    onClick={handleDelete}
                    className="text-destructive flex items-center justify-center gap-2 py-1 text-sm font-medium"
                >
                    <Trash2 className="size-4" /> {t('tasks.delete_task')}
                </button>
            </div>
        </ResponsiveModal>
    );
}
