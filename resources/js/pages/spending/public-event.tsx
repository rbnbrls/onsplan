import { CategoryIcon } from '@/components/category-icon';
import { categoryMap, type CategoryDef } from '@/lib/categories';
import { money } from '@/lib/money';
import { Head } from '@inertiajs/react';

type Spend = {
    id: number;
    amount: number;
    currency: string;
    base_amount: number;
    category: string;
    note: string | null;
    payer: string | null;
    spent_at: string;
};
type Props = {
    event: { name: string; currency: string; total: number; members: { id: number; name: string }[] };
    categories: CategoryDef[];
    spends: Spend[];
};

const dayLabel = (date: string) => new Date(date + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });

// Read-only public view reached via a share link. No app shell, no auth, no editing. This page has no
// i18n context, so a category's English `name` doubles as its label (defaults ship English names).
export default function PublicEvent({ event, categories, spends }: Props) {
    const catMap = categoryMap(categories);
    const catText = (key: string) => catMap[key]?.name ?? key.charAt(0).toUpperCase() + key.slice(1);
    return (
        <div className="bg-background min-h-screen">
            <Head title={`${event.name} · onsplan`} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div className="flex items-center gap-2 pt-2">
                    <span className="bg-tile text-tile-foreground flex size-7 items-center justify-center rounded-lg text-xs font-bold">FW</span>
                    <span className="text-muted-foreground text-sm">onsplan · shared with you</span>
                </div>

                <div className="bg-surface-dark text-text-inverse rounded-2xl p-5">
                    <div className="text-accent-light text-xs tracking-widest uppercase">Total spent</div>
                    <div className="font-heading mt-1 text-4xl">{money(event.total, event.currency)}</div>
                    <div className="text-accent-light mt-1 text-sm">
                        {event.name} · {event.members.length} people · {spends.length} spends
                    </div>
                </div>

                <section className="flex flex-col gap-2">
                    <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Spends</h2>
                    {spends.map((s) => (
                        <div key={s.id} className="bg-surface-card flex items-center gap-3 rounded-xl border p-3">
                                <div className="flex min-w-0 flex-1 items-center gap-3">
                                    <span className="bg-accent-subtle text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">
                                        <CategoryIcon icon={catMap[s.category]?.icon} className="size-4.5" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="text-primary line-clamp-2 text-sm font-medium">{s.note || catText(s.category)}</div>
                                        <div className="text-muted-foreground truncate text-xs">
                                            {(s.payer ?? 'Someone').split(' ')[0]} · {dayLabel(s.spent_at)}
                                        </div>
                                    </div>
                                </div>
                                <div className="shrink-0 text-right">
                                    <div className="text-primary font-mono text-sm font-semibold tabular-nums">{money(s.amount, s.currency)}</div>
                                    {s.currency !== event.currency && (
                                        <div className="text-muted-foreground font-mono text-xs tabular-nums">
                                            {money(s.base_amount, event.currency)}
                                        </div>
                                    )}
                                </div>
                            </div>
                    ))}
                </section>

                <p className="text-muted-foreground py-4 text-center text-xs">This is a read-only view shared from onsplan.</p>
            </div>
        </div>
    );
}
