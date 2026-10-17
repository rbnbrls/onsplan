import { Head, router } from '@inertiajs/react';
import { CalendarPlus, ChevronLeft, ChevronRight, Filter, Plus } from 'lucide-react';
import { useState } from 'react';

import { EventSheet } from '@/components/calendar/event-sheet';
import { FeedSheet } from '@/components/calendar/feed-sheet';
import { FilterSheet } from '@/components/calendar/filter-sheet';
import { MonthView } from '@/components/calendar/month-view';
import { TimelineView } from '@/components/calendar/timeline-view';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useAddAction } from '@/lib/add-action';
import { filterItems, shiftDate, ymd, type CalFilter, type CalItem } from '@/lib/calendar';
import { openNewOnArrival } from '@/lib/create-intent';
import { useLocale, useT } from '@/lib/i18n';

const FILTER_KEY = 'onsplan:calendarFilter';
const readFilter = (): CalFilter => {
    try {
        const v = JSON.parse(localStorage.getItem(FILTER_KEY) ?? '');
        return { people: Array.isArray(v?.people) ? v.people : [], sources: Array.isArray(v?.sources) ? v.sources : [] };
    } catch {
        return { people: [], sources: [] };
    }
};

type Named = { id: number; name: string };
export type CalendarProps = {
    view: 'month' | 'timeline';
    zoom: 'week' | 'month';
    date: string;
    range: { from: string; to: string };
    items: CalItem[];
    sources: string[];
    members: Named[];
    contacts: Named[];
    projects: Named[] | null;
    feedUrl: string | null;
};

// View, zoom and date live in the query string (back/forward + shareable); the server answers with the range
// and its items. Navigation is a partial reload of only those props: members/contacts/projects stay put.
export default function CalendarIndex({ view, zoom, date, range, items, sources, members, contacts, projects, feedUrl }: CalendarProps) {
    const t = useT();
    const locale = useLocale();
    const todayYmd = ymd(new Date());
    // Phones list the selected day under the grid: today when it's in the shown month, else the date asked for.
    const [selected, setSelected] = useState(date.slice(0, 7) === todayYmd.slice(0, 7) ? todayYmd : date);
    const [sheet, setSheet] = useState<{ open: boolean; day: string }>(() => ({ open: openNewOnArrival(), day: selected }));
    const add = (day = selected) => setSheet({ open: true, day });
    useAddAction(() => add());

    // Drop any remembered source/person no longer valid for this family (a module disabled since it was
    // saved, or a removed member/contact) so a stale filter never silently hides everything.
    const validSources = new Set(sources);
    const validPeople = new Set([...members.map((m) => `user:${m.id}`), ...contacts.map((c) => `person:${c.id}`)]);
    const sanitizeFilter = (f: CalFilter): CalFilter => ({
        people: f.people.filter((p) => validPeople.has(p)),
        sources: f.sources.filter((s) => validSources.has(s)),
    });
    const [filter, setFilterState] = useState<CalFilter>(() => sanitizeFilter(readFilter()));
    const [filtering, setFiltering] = useState(false);
    const [feedOpen, setFeedOpen] = useState(false);
    const setFilter = (f: CalFilter) => {
        setFilterState(f);
        try {
            localStorage.setItem(FILTER_KEY, JSON.stringify(f));
        } catch {
            /* private mode: not remembered */
        }
    };
    const shown = filterItems(items, filter);
    const active = filter.people.length + filter.sources.length;

    const go = (q: Partial<Pick<CalendarProps, 'view' | 'zoom' | 'date'>>) => {
        if (q.date) setSelected(q.date);
        router.get(
            '/m/calendar',
            { view, zoom, date, ...q },
            { preserveState: true, preserveScroll: true, only: ['view', 'zoom', 'date', 'range', 'items'] },
        );
    };
    const label = new Date(date + 'T12:00').toLocaleDateString(locale, { month: 'long', year: 'numeric' });

    return (
        <AppLayout breadcrumbs={[{ title: t('nav.calendar'), href: '/m/calendar' }]}>
            <Head title={t('nav.calendar')} />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-4 p-4">
                <div className="flex items-center gap-1">
                    <h1 className="font-heading text-primary mr-auto truncate text-2xl first-letter:uppercase">{label}</h1>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={t('calendar.previous')}
                        onClick={() => go({ date: shiftDate(view, zoom, date, -1) })}
                    >
                        <ChevronLeft className="size-5" />
                    </Button>
                    <Button variant="outline" size="sm" className="rounded-full" onClick={() => go({ date: todayYmd })}>
                        {t('calendar.today')}
                    </Button>
                    <Button variant="ghost" size="icon" aria-label={t('calendar.next')} onClick={() => go({ date: shiftDate(view, zoom, date, 1) })}>
                        <ChevronRight className="size-5" />
                    </Button>
                    <Button variant="outline" size="sm" onClick={() => setFiltering(true)}>
                        <Filter className="size-4" />
                        {active > 0 ? ` ${active}` : ''}
                    </Button>
                    <Button variant="ghost" size="icon" aria-label={t('calendar.subscribe')} onClick={() => setFeedOpen(true)}>
                        <CalendarPlus className="size-5" />
                    </Button>
                    {/* Hidden on mobile: the nav's context-aware "+" adds an event there. */}
                    <Button size="icon" className="ml-2 hidden rounded-full md:inline-flex" onClick={() => add()} aria-label={t('calendar.new')}>
                        <Plus className="size-5" />
                    </Button>
                </div>

                {/* View switch, plus the zoom on the timeline. Both are query params, so each tap is a partial reload
                    and the server answers with the matching range. */}
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Segmented value={view} options={['month', 'timeline'] as const} onChange={(v) => go({ view: v })} />
                    {view === 'timeline' && <Segmented value={zoom} options={['week', 'month'] as const} onChange={(z) => go({ zoom: z })} />}
                </div>
                {view === 'timeline' ? (
                    <TimelineView items={shown} range={range} />
                ) : (
                    <MonthView items={shown} range={range} date={date} selected={selected} onSelect={setSelected} onAdd={add} />
                )}
            </div>
            <EventSheet
                open={sheet.open}
                onOpenChange={(open) => setSheet((s) => ({ ...s, open }))}
                defaultDate={sheet.day}
                members={members}
                contacts={contacts}
                projects={projects}
            />
            <FilterSheet
                open={filtering}
                onOpenChange={setFiltering}
                value={filter}
                onChange={setFilter}
                sources={sources}
                members={members}
                contacts={contacts}
            />
            <FeedSheet open={feedOpen} onOpenChange={setFeedOpen} url={feedUrl} />
        </AppLayout>
    );
}

// Pill segmented control; labels are the calendar.<option> strings (month / timeline / week).
function Segmented<T extends string>({ value, options, onChange }: { value: T; options: readonly T[]; onChange: (v: T) => void }) {
    const t = useT();
    return (
        <div className="bg-surface-card flex rounded-full border p-0.5 text-sm">
            {options.map((o) => (
                <button
                    key={o}
                    type="button"
                    aria-pressed={value === o}
                    onClick={() => value !== o && onChange(o)}
                    className={`rounded-full px-3 py-1 transition-colors ${value === o ? 'bg-accent-subtle text-primary font-medium' : 'text-muted-foreground hover:text-primary'}`}
                >
                    {t(`calendar.${o}`)}
                </button>
            ))}
        </div>
    );
}
