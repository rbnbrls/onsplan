import { Head, router, useForm } from '@inertiajs/react';
import { RefreshCw, Save } from 'lucide-react';
import { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';

type Settings = {
    enabled: boolean;
    postal_code: string | null;
    house_number: string | null;
    house_number_suffix: string | null;
    street_name: string | null;
    collector: string | null;
    selected_waste_types: string[] | null;
    collector_detection_status: string;
    task_offset_days: number;
    task_time: string;
    sync_horizon_months: number;
    status: string;
    last_synced_at: string | null;
    last_error: string | null;
};
type Props = { settings: Settings; providers: { id: string; name: string }[]; wasteTypes: { id: string; label: string }[]; canManage: boolean };

export default function HouseholdSettings({ settings, providers, wasteTypes }: Props) {
    const form = useForm({
        postal_code: settings.postal_code ?? '',
        house_number: settings.house_number ?? '',
        house_number_suffix: settings.house_number_suffix ?? '',
        street_name: settings.street_name ?? '',
        collector: settings.collector ?? '',
        selected_waste_types: settings.selected_waste_types ?? [],
        task_offset_days: settings.task_offset_days ?? 1,
        task_time: settings.task_time ?? '18:00',
        sync_horizon_months: settings.sync_horizon_months ?? 3,
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.patch('/settings/household', { preserveScroll: true });
    };
    const toggle = (id: string) =>
        form.setData(
            'selected_waste_types',
            form.data.selected_waste_types.includes(id)
                ? form.data.selected_waste_types.filter((x) => x !== id)
                : [...form.data.selected_waste_types, id],
        );
    return (
        <AppLayout breadcrumbs={[{ title: 'Household', href: '/settings/household' } satisfies BreadcrumbItem]}>
            <Head title="Household · Waste collection" />
            <SettingsLayout>
                <div className="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4 md:p-6">
                    <div>
                        <h1 className="font-heading text-primary text-2xl">Waste collection</h1>
                        <p className="text-muted-foreground text-sm">Automatically add collection dates to your calendar and tasks.</p>
                    </div>
                    <form onSubmit={submit} className="bg-surface-card flex flex-col gap-5 rounded-2xl border p-6">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label>Postal code</Label>
                                <Input
                                    value={form.data.postal_code}
                                    onChange={(e) => form.setData('postal_code', e.target.value)}
                                    placeholder="1234AB"
                                    required
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label>House number</Label>
                                <Input value={form.data.house_number} onChange={(e) => form.setData('house_number', e.target.value)} required />
                            </div>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label>Suffix</Label>
                                <Input
                                    value={form.data.house_number_suffix}
                                    onChange={(e) => form.setData('house_number_suffix', e.target.value)}
                                    placeholder="Optional"
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label>Street name</Label>
                                <Input
                                    value={form.data.street_name}
                                    onChange={(e) => form.setData('street_name', e.target.value)}
                                    placeholder="Required by some providers"
                                />
                            </div>
                        </div>
                        <div className="grid gap-2">
                            <Label>Waste collector</Label>
                            <select
                                value={form.data.collector}
                                onChange={(e) => form.setData('collector', e.target.value)}
                                className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                            >
                                <option value="">Auto-detect</option>
                                {providers.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label>Waste types</Label>
                            <div className="flex flex-wrap gap-2">
                                {wasteTypes.length ? (
                                    wasteTypes.map((w) => (
                                        <button
                                            type="button"
                                            key={w.id}
                                            onClick={() => toggle(w.id)}
                                            className={`rounded-full border px-3 py-1 text-sm ${form.data.selected_waste_types.includes(w.id) ? 'border-primary bg-accent-subtle' : 'border-border'}`}
                                        >
                                            {w.label}
                                        </button>
                                    ))
                                ) : (
                                    <p className="text-muted-foreground text-sm">Save the address first to discover waste types.</p>
                                )}
                            </div>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-2">
                                <Label>Task days before</Label>
                                <Input
                                    type="number"
                                    min="0"
                                    max="7"
                                    value={form.data.task_offset_days}
                                    onChange={(e) => form.setData('task_offset_days', Number(e.target.value))}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label>Task time</Label>
                                <Input type="time" value={form.data.task_time} onChange={(e) => form.setData('task_time', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label>Months ahead</Label>
                                <Input
                                    type="number"
                                    min="1"
                                    max="12"
                                    value={form.data.sync_horizon_months}
                                    onChange={(e) => form.setData('sync_horizon_months', Number(e.target.value))}
                                />
                            </div>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button type="submit" disabled={form.processing}>
                                <Save className="size-4" />
                                Save and sync
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => router.post('/settings/household/sync', {}, { preserveScroll: true })}
                            >
                                <RefreshCw className="size-4" />
                                Sync now
                            </Button>
                        </div>
                        {settings.last_error && <p className="text-destructive text-sm">{settings.last_error}</p>}
                        {settings.last_synced_at && <p className="text-muted-foreground text-xs">Last synced: {settings.last_synced_at}</p>}
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
