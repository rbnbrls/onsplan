import { CategoryIcon } from '@/components/category-icon';
import { DuplicateWarning } from '@/components/duplicate-warning';
import { Picker } from '@/components/picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useOptimistic } from '@/hooks/use-optimistic';
import { catLabel, categoryMap, type CategoryDef } from '@/lib/categories';
import { useT } from '@/lib/i18n';
import { currencyOptions, money, selectAllOnFocus } from '@/lib/money';
import { Head, router, useForm } from '@inertiajs/react';
import { Banknote, Pencil, Plus, Trash2, User } from 'lucide-react';
import { FormEventHandler, useEffect, useMemo, useRef, useState } from 'react';

type Guest = { id: number; name: string; role: 'viewer' | 'editor' };
type Spend = {
    id: number;
    amount: number;
    currency: string;
    base_amount: number;
    category: string;
    note: string | null;
    payer: { name: string | null; guestId: number | null };
    spent_at: string;
};
type Balance = { id: string; name: string; net: number };
type Suggestion = { from: { id: string; name: string }; to: { id: string; name: string }; amount: number };
type Transfer = { id: number; from: string; to: string; amount: number; note: string | null; date: string };
type EventT = { name: string; currency: string; total: number; categories: CategoryDef[]; currencies: string[] };

type Props = {
    token: string;
    event: EventT;
    guests: Guest[];
    spends: Spend[];
    balances: Balance[];
    suggestions: Suggestion[];
    transfers: Transfer[];
};

const today = () => new Date().toISOString().slice(0, 10);
const dayLabel = (date: string) => new Date(date + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });

// Public guest page reached via the link (after any password gate). No account: the visitor picks
// who they are from the guest list; that choice is remembered in localStorage per token. Editors get
// an add-expense form and can edit/delete the spends they added.
export default function GuestEvent({ token, event, guests, spends, balances, suggestions, transfers }: Props) {
    const t = useT();
    // Dynamic categories: key→def lookup for rendering the spend log (the form builds its own).
    const catMap = categoryMap(event.categories);
    const storageKey = `onsplan:guest:${token}`;
    const [meId, setMeId] = useState<number | null>(null);
    // Read the saved identity after mount (no SSR window), and drop it if that guest was removed.
    useEffect(() => {
        const saved = Number(localStorage.getItem(storageKey));
        setMeId(saved && guests.some((g) => g.id === saved) ? saved : null);
    }, [storageKey, guests]);

    const me = useMemo(() => guests.find((g) => g.id === meId) ?? null, [guests, meId]);
    const pick = (id: number) => {
        localStorage.setItem(storageKey, String(id));
        setMeId(id);
    };
    const forget = () => {
        localStorage.removeItem(storageKey);
        setMeId(null);
    };

    const [editing, setEditing] = useState<Spend | null>(null);
    const [adding, setAdding] = useState(false);
    const [settling, setSettling] = useState(false); // hooks must run before the identity-gate early return
    // Optimistic overlays (hooks, so also above the early return): a deleted spend row vanishes on tap and
    // a marked-paid suggestion disappears on tap; both snap back only if the request fails.
    const removedOpt = useOptimistic<boolean>();
    const settledOpt = useOptimistic<boolean>();
    const visibleSpends = spends.filter((s) => !removedOpt.get(s.id, false));
    const visibleSuggestions = suggestions.filter((s) => !settledOpt.get(`${s.from.id}-${s.to.id}`, false));
    const showForm = adding || editing !== null;

    // Identity gate: until the visitor says who they are, show only the picker.
    if (!me) {
        return (
            <Shell title={event.name}>
                <div className="bg-surface-dark text-text-inverse rounded-2xl p-5">
                    <div className="text-accent-light text-xs tracking-widest uppercase">{event.name}</div>
                    <div className="font-heading mt-1 text-3xl">{money(event.total, event.currency)}</div>
                    <div className="text-accent-light mt-1 text-sm">{t('spending.shared_with_you')}</div>
                </div>
                <section className="mt-2 flex flex-col gap-2">
                    <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('spending.who_are_you')}</h2>
                    {guests.length === 0 && <p className="text-muted-foreground text-sm">{t('spending.no_guests_yet')}</p>}
                    {guests.map((g) => (
                        <button
                            key={g.id}
                            onClick={() => pick(g.id)}
                            className="bg-surface-card hover:border-accent flex items-center gap-3 rounded-xl border p-3 text-left transition-colors"
                        >
                            <span className="bg-accent-subtle text-primary flex size-9 items-center justify-center rounded-full text-sm font-medium">
                                {g.name.charAt(0).toUpperCase()}
                            </span>
                            <span className="text-primary flex-1 font-medium">{g.name}</span>
                            <span className="text-muted-foreground text-xs">
                                {t(g.role === 'editor' ? 'spending.can_add' : 'spending.view_only')}
                            </span>
                        </button>
                    ))}
                </section>
            </Shell>
        );
    }

    const myKey = `g${me.id}`;
    const myBalance = balances.find((b) => b.id === myKey);

    // One-click record of a suggested payment (records the exact suggested amount, no note).
    const markPaid = (s: Suggestion) => {
        const key = `${s.from.id}-${s.to.id}`;
        settledOpt.set(key, true);
        router.post(
            `/m/spending/guest/${token}/settle`,
            // add_anyway: a suggestion is the outstanding balance, never the duplicate the server check guards against.
            { guest_id: me.id, from_key: s.from.id, to_key: s.to.id, amount: s.amount, add_anyway: true },
            { preserveScroll: true, preserveState: true, onSuccess: () => settledOpt.clear(key), onError: () => settledOpt.clear(key) },
        );
    };

    return (
        <Shell title={event.name}>
            {/* Identity bar */}
            <div className="flex items-center gap-2 text-sm">
                <span className="bg-accent-strong text-accent-strong-foreground flex size-7 items-center justify-center rounded-full text-xs font-medium">
                    {me.name.charAt(0).toUpperCase()}
                </span>
                <span className="text-primary font-medium">{t('spending.you_are', { name: me.name })}</span>
                <button onClick={forget} className="text-muted-foreground hover:text-primary ml-1 underline">
                    {t('spending.change')}
                </button>
            </div>

            {/* Total + my standing */}
            <div className="bg-surface-dark text-text-inverse rounded-2xl p-5">
                <div className="text-accent-light text-xs tracking-widest uppercase">{t('spending.total_spent')}</div>
                <div className="font-heading mt-1 text-4xl">{money(event.total, event.currency)}</div>
                <div className="text-accent-light mt-1 text-sm">
                    {event.name} · {guests.length} {t('spending.person_other')} · {spends.length} {t('spending.spend_other')}
                </div>
                {myBalance && myBalance.net !== 0 && (
                    <div className="border-text-inverse/15 mt-3 border-t pt-3 text-sm">
                        {t(myBalance.net > 0 ? 'spending.you_get_back' : 'spending.you_owe')}{' '}
                        <span className="font-mono font-semibold tabular-nums">{money(Math.abs(myBalance.net), event.currency)}</span>
                    </div>
                )}
            </div>

            {/* Balances */}
            <section className="flex flex-col gap-2">
                <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('spending.balances')}</h2>
                {balances.map((b) => (
                    <div
                        key={b.id}
                        className={`flex items-center justify-between rounded-xl border p-3 ${b.id === myKey ? 'border-primary bg-accent-subtle' : 'bg-surface-card'}`}
                    >
                        <div className="flex items-center gap-3">
                            <span className="bg-accent-strong text-accent-strong-foreground flex size-8 items-center justify-center rounded-full text-sm font-medium">
                                {b.name.charAt(0).toUpperCase()}
                            </span>
                            <div>
                                <div className="text-primary text-sm font-medium">{b.name}</div>
                                <div className="text-muted-foreground text-xs">
                                    {t(b.net > 0 ? 'spending.gets_back' : b.net < 0 ? 'spending.owes' : 'spending.settled_up')}
                                </div>
                            </div>
                        </div>
                        <div
                            className={`font-mono text-sm font-semibold tabular-nums ${b.net > 0 ? 'text-primary' : b.net < 0 ? 'text-[var(--warning)]' : 'text-muted-foreground'}`}
                        >
                            {b.net > 0 ? '+' : ''}
                            {money(b.net, event.currency)}
                        </div>
                    </div>
                ))}
            </section>

            {/* Suggested settle-up. A guest can one-click record a suggestion that involves them. */}
            {visibleSuggestions.length > 0 && (
                <section className="flex flex-col gap-2">
                    <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('spending.suggested_payments')}</h2>
                    {visibleSuggestions.map((s, i) => {
                        const involvesMe = s.from.id === myKey || s.to.id === myKey;
                        return (
                            <div key={i} className="bg-surface-card flex items-center justify-between gap-2 rounded-xl border p-3 text-sm">
                                <div>
                                    <span className="text-primary font-medium">{s.from.name}</span>
                                    <span className="text-muted-foreground"> {t('spending.pays')} </span>
                                    <span className="text-primary font-medium">{s.to.name}</span>
                                    <span className="text-primary ml-2 font-mono font-semibold tabular-nums">{money(s.amount, event.currency)}</span>
                                </div>
                                {me.role === 'editor' && involvesMe && (
                                    <Button size="sm" variant="outline" onClick={() => markPaid(s)} className="shrink-0">
                                        {t('spending.mark_paid')}
                                    </Button>
                                )}
                            </div>
                        );
                    })}
                </section>
            )}

            {/* Recorded transfers + a free-form record button (editors only). */}
            <section className="flex flex-col gap-2">
                <div className="flex items-center justify-between">
                    <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('spending.transfers')}</h2>
                    {me.role === 'editor' && balances.length >= 2 && (
                        <button type="button" onClick={() => setSettling(true)} className="text-primary flex items-center gap-1 text-sm font-medium">
                            <Plus className="size-4" /> {t('spending.record')}
                        </button>
                    )}
                </div>
                {transfers.length === 0 && <p className="text-muted-foreground text-sm">{t('spending.no_transfers_yet')}</p>}
                {transfers.map((tr) => (
                    <div key={tr.id} className="bg-surface-card flex items-center gap-3 rounded-xl border p-3 text-sm">
                        <div className="min-w-0 flex-1">
                            <div className="text-primary font-medium">
                                {tr.from} → {tr.to}
                            </div>
                            {tr.note && <div className="text-primary/80 truncate text-xs">{tr.note}</div>}
                            <div className="text-muted-foreground text-xs">{dayLabel(tr.date)}</div>
                        </div>
                        <span className="text-primary shrink-0 font-mono font-semibold tabular-nums">{money(tr.amount, event.currency)}</span>
                    </div>
                ))}
            </section>

            {/* Spends */}
            <section className="flex flex-col gap-2 pb-24">
                <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('spending.spends_heading')}</h2>
                {visibleSpends.map((s) => {
                    const mine = s.payer.guestId === me.id;
                    return (
                        <div key={s.id} className="bg-surface-card flex items-center gap-3 rounded-xl border p-3">
                            <span className="bg-accent-subtle text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">
                                <CategoryIcon icon={catMap[s.category]?.icon} className="size-4.5" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="text-primary line-clamp-2 text-sm font-medium">{s.note || catLabel(t, catMap, s.category)}</div>
                                <div className="text-muted-foreground truncate text-xs capitalize">
                                    {(s.payer.name ?? t('spending.guest_word')).split(' ')[0]} · {dayLabel(s.spent_at)}
                                </div>
                            </div>
                            <div className="shrink-0 text-right">
                                <div className="text-primary font-mono text-sm font-semibold tabular-nums">{money(s.amount, s.currency)}</div>
                                {s.currency !== event.currency && (
                                    <div className="text-muted-foreground font-mono text-xs tabular-nums">{money(s.base_amount, event.currency)}</div>
                                )}
                            </div>
                            {/* Editors manage only their own entries. */}
                            {me.role === 'editor' && mine && (
                                <div className="flex flex-col gap-1">
                                    <button
                                        onClick={() => setEditing(s)}
                                        aria-label={t('common.edit')}
                                        className="text-muted-foreground hover:text-primary flex size-7 items-center justify-center"
                                    >
                                        <Pencil className="size-4" />
                                    </button>
                                    <button
                                        onClick={() => {
                                            removedOpt.set(s.id, true);
                                            router.delete(`/m/spending/guest/${token}/spends/${s.id}`, {
                                                data: { guest_id: me.id },
                                                preserveScroll: true,
                                                preserveState: true,
                                                onSuccess: () => removedOpt.clear(s.id),
                                                onError: () => removedOpt.clear(s.id),
                                            });
                                        }}
                                        aria-label={t('common.delete')}
                                        className="text-muted-foreground flex size-7 items-center justify-center hover:text-[var(--warning)]"
                                    >
                                        <Trash2 className="size-4" />
                                    </button>
                                </div>
                            )}
                        </div>
                    );
                })}
                {visibleSpends.length === 0 && <p className="text-muted-foreground text-sm">{t('spending.no_spends_yet')}</p>}
            </section>

            {/* Editor add button */}
            {me.role === 'editor' && (
                <button
                    onClick={() => {
                        setEditing(null);
                        setAdding(true);
                    }}
                    className="bg-accent-strong text-accent-strong-foreground fixed right-4 bottom-6 z-10 flex items-center gap-2 rounded-full px-5 py-3 text-sm font-semibold shadow-lg sm:right-[calc(50%-20rem)]"
                >
                    <Plus className="size-5" /> {t('spending.add_expense')}
                </button>
            )}

            {showForm && (
                <SpendForm
                    token={token}
                    me={me}
                    event={event}
                    editing={editing}
                    onClose={() => {
                        setAdding(false);
                        setEditing(null);
                    }}
                />
            )}

            {settling && (
                <SettleForm token={token} me={me} event={event} others={balances.filter((b) => b.id !== myKey)} onClose={() => setSettling(false)} />
            )}
        </Shell>
    );
}

// Shared page chrome: no app shell, no auth. Same visual language as the read-only public view.
function Shell({ title, children }: { title: string; children: React.ReactNode }) {
    const t = useT();
    return (
        <div className="bg-background min-h-screen">
            <Head title={`${title} · onsplan`} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div className="flex items-center gap-2 pt-2">
                    <span className="bg-tile text-tile-foreground flex size-7 items-center justify-center rounded-lg text-xs font-bold">FW</span>
                    <span className="text-muted-foreground text-sm">onsplan · {t('spending.shared_with_you')}</span>
                </div>
                {children}
            </div>
        </div>
    );
}

function SpendForm({ token, me, event, editing, onClose }: { token: string; me: Guest; event: EventT; editing: Spend | null; onClose: () => void }) {
    const t = useT();
    const catMap = categoryMap(event.categories);
    const defaultCategory = event.categories[0]?.key ?? 'food';
    const amountRef = useRef<HTMLInputElement>(null); // focused on open so the numeric pad shows
    const form = useForm({
        guest_id: me.id,
        amount: editing ? (editing.amount / 100).toString() : '',
        currency: editing?.currency ?? event.currency,
        category: editing?.category ?? defaultCategory,
        note: editing?.note ?? '',
        spent_at: editing?.spent_at ?? today(),
        add_anyway: false, // set on "Add anyway" after the server's soft duplicate warning (errors.add_anyway)
    });

    // addAnyway: the guest confirmed the server's soft duplicate warning, so the re-post skips the check.
    const send = (addAnyway = false) => {
        const opts = { preserveScroll: true, onSuccess: onClose };
        form.transform((d) => ({ ...d, add_anyway: addAnyway }));
        if (editing) {
            form.patch(`/m/spending/guest/${token}/spends/${editing.id}`, opts);
        } else {
            form.post(`/m/spending/guest/${token}/spends`, opts);
        }
    };
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        send();
    };

    return (
        // Keyboard-safe drawer: pinned header + footer, only the fields scroll (see ResponsiveModal).
        <ResponsiveModal
            open
            onOpenChange={(v) => !v && onClose()}
            title={t(editing ? 'spending.edit_expense' : 'spending.add_expense')}
            description={t('spending.paid_by', { name: me.name })}
            autoFocusRef={amountRef}
            footer={
                <div className="flex flex-col gap-2">
                    {/* Soft duplicate warning from the server: Add anyway re-posts with add_anyway. */}
                    {form.errors.add_anyway && (
                        <DuplicateWarning message={form.errors.add_anyway}>
                            <Button type="button" size="sm" disabled={form.processing} onClick={() => send(true)}>
                                {t(editing ? 'spending.save_anyway' : 'spending.add_anyway')}
                            </Button>
                            <Button type="button" size="sm" variant="ghost" onClick={onClose}>
                                {t('common.cancel')}
                            </Button>
                        </DuplicateWarning>
                    )}
                    <Button type="submit" form="guest-spend-form" disabled={!form.data.amount.trim() || form.processing} className="w-full">
                        {form.processing ? t('spending.saving') : t(editing ? 'spending.save_changes' : 'spending.add_expense')}
                    </Button>
                </div>
            }
        >
            {/* The <form> carries no fields directly; inputs join it via form="guest-spend-form" so the
                pinned footer button (rendered outside this scroll region) can still submit. */}
            <form id="guest-spend-form" onSubmit={submit} className="hidden" />
            <div className="flex flex-col gap-4 pt-1">
                <div className="flex items-start gap-2">
                    <div className="grid flex-1 gap-1.5">
                        <Label htmlFor="amount">{t('spending.amount')}</Label>
                        <Input
                            id="amount"
                            ref={amountRef}
                            form="guest-spend-form"
                            inputMode="decimal"
                            onFocus={selectAllOnFocus}
                            value={form.data.amount}
                            onChange={(e) => form.setData('amount', e.target.value)}
                            placeholder="0.00"
                        />
                    </div>
                    <div className="grid w-28 gap-1.5">
                        <Label htmlFor="guest-currency">{t('spending.currency')}</Label>
                        <Picker
                            id="guest-currency"
                            fallbackIcon={Banknote}
                            options={currencyOptions(event.currencies)}
                            selected={[form.data.currency]}
                            onChange={([c]) => c && form.setData('currency', c)}
                        />
                    </div>
                </div>

                <div className="grid gap-1.5">
                    <Label>{t('spending.category')}</Label>
                    <div className="flex flex-wrap gap-2">
                        {event.categories.map((c) => {
                            const active = form.data.category === c.key;
                            return (
                                <button
                                    key={c.key}
                                    type="button"
                                    onClick={() => form.setData('category', c.key)}
                                    aria-pressed={active}
                                    className={`flex items-center gap-2 rounded-full border px-4 py-2 text-sm font-medium transition-colors ${
                                        active
                                            ? 'border-primary bg-accent-strong text-accent-strong-foreground'
                                            : 'border-border text-primary hover:bg-surface'
                                    }`}
                                >
                                    <CategoryIcon icon={c.icon} className="size-4 shrink-0" />
                                    {catLabel(t, catMap, c.key)}
                                </button>
                            );
                        })}
                    </div>
                </div>

                <div className="grid gap-1.5">
                    <Label htmlFor="note">{t('spending.note')}</Label>
                    <Input
                        id="note"
                        form="guest-spend-form"
                        value={form.data.note}
                        onChange={(e) => form.setData('note', e.target.value)}
                        placeholder={t('spending.optional_placeholder')}
                    />
                </div>

                <div className="grid gap-1.5">
                    <Label htmlFor="spent_at">{t('spending.date')}</Label>
                    <Input
                        id="spent_at"
                        form="guest-spend-form"
                        type="date"
                        value={form.data.spent_at}
                        onChange={(e) => form.setData('spent_at', e.target.value)}
                    />
                </div>
            </div>
        </ResponsiveModal>
    );
}

// A guest records a settle-up they are part of: a direction toggle (I paid / I was paid) puts them on
// the right side, and the counterparty is any other participant. The server re-checks own-involvement.
function SettleForm({ token, me, event, others, onClose }: { token: string; me: Guest; event: EventT; others: Balance[]; onClose: () => void }) {
    const t = useT();
    const myKey = `g${me.id}`;
    const [paid, setPaid] = useState(true); // true = I paid them; false = they paid me
    const [otherKey, setOtherKey] = useState(others[0]?.id ?? '');
    const form = useForm({ guest_id: me.id, from_key: '', to_key: '', amount: '', note: '', add_anyway: false });

    // addAnyway: the guest confirmed the server's "already recorded?" warning (errors.add_anyway).
    const send = (addAnyway = false) => {
        form.transform((d) => ({
            ...d,
            from_key: paid ? myKey : otherKey,
            to_key: paid ? otherKey : myKey,
            amount: Math.round(Number(d.amount) * 100),
            add_anyway: addAnyway,
        }));
        form.post(`/m/spending/guest/${token}/settle`, { preserveScroll: true, onSuccess: onClose });
    };
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        send();
    };

    const valid = !!otherKey && Number(form.data.amount) > 0;

    return (
        <ResponsiveModal
            open
            onOpenChange={(v) => !v && onClose()}
            title={t('spending.record_payment')}
            footer={
                <div className="flex flex-col gap-2">
                    {form.errors.add_anyway && (
                        <DuplicateWarning message={form.errors.add_anyway}>
                            <Button type="button" size="sm" onClick={() => send(true)} disabled={form.processing}>
                                {t('spending.record_anyway')}
                            </Button>
                            <Button type="button" size="sm" variant="ghost" onClick={onClose}>
                                {t('common.cancel')}
                            </Button>
                        </DuplicateWarning>
                    )}
                    <Button type="submit" form="guest-settle-form" disabled={!valid || form.processing} className="w-full">
                        {form.processing ? t('spending.saving') : t('common.save')}
                    </Button>
                </div>
            }
        >
            <form id="guest-settle-form" onSubmit={submit} className="hidden" />
            <div className="flex flex-col gap-4 pt-1">
                {/* Direction: which side of the transfer the guest is on. */}
                <div className="bg-surface grid grid-cols-2 gap-1 rounded-lg border p-1">
                    {[
                        [true, t('spending.i_paid')],
                        [false, t('spending.i_was_paid')],
                    ].map(([v, label]) => (
                        <button
                            key={String(v)}
                            type="button"
                            onClick={() => setPaid(v as boolean)}
                            className={`rounded-md py-1.5 text-sm font-medium transition-colors ${paid === v ? 'bg-accent-strong text-accent-strong-foreground' : 'text-text-secondary'}`}
                        >
                            {label as string}
                        </button>
                    ))}
                </div>

                <div className="grid gap-1.5">
                    <Label htmlFor="guest-counterparty">{paid ? t('spending.to') : t('spending.from')}</Label>
                    <Picker
                        id="guest-counterparty"
                        fallbackIcon={User}
                        options={others.map((o) => ({ value: o.id, label: o.name }))}
                        selected={otherKey ? [otherKey] : []}
                        onChange={([k]) => k && setOtherKey(k)}
                    />
                </div>

                <div className="grid gap-1.5">
                    <Label htmlFor="settle-amount">{t('spending.amount_currency', { currency: event.currency })}</Label>
                    <Input
                        id="settle-amount"
                        form="guest-settle-form"
                        inputMode="decimal"
                        onFocus={selectAllOnFocus}
                        value={form.data.amount}
                        onChange={(e) => form.setData('amount', e.target.value.replace(/,/g, '.').replace(/[^\d.]/g, ''))}
                        placeholder="0.00"
                    />
                </div>

                <div className="grid gap-1.5">
                    <Label htmlFor="settle-note">{t('spending.note_optional')}</Label>
                    <Input
                        id="settle-note"
                        form="guest-settle-form"
                        value={form.data.note}
                        onChange={(e) => form.setData('note', e.target.value)}
                        placeholder={t('spending.transfer_note_placeholder')}
                        maxLength={255}
                    />
                </div>
            </div>
        </ResponsiveModal>
    );
}
