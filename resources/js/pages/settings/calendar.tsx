import { Head, router } from '@inertiajs/react';
import { Calendar, CheckCircle2, Link2, RefreshCw, Unlink } from 'lucide-react';
import { useState } from 'react';

import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { useT } from '@/lib/i18n';

type CalendarOption = { id: string; name: string; canEdit: boolean };
type Integration = { calendarName: string; email: string | null; status: string; lastSyncedAt: string | null; lastError: string | null };
type NextcloudIntegration = { calendarName: string; username: string | null; serverUrl: string | null; status: string; lastSyncedAt: string | null; lastError: string | null };

export default function CalendarSettings({ configured, integration, pendingCalendars, nextcloud, pendingNextcloudCalendars }: { configured: boolean; integration: Integration | null; pendingCalendars: CalendarOption[]; nextcloud: NextcloudIntegration | null; pendingNextcloudCalendars: CalendarOption[] }) {
    const t = useT();
    const [selectedCalendar, setSelectedCalendar] = useState('');
    const [nextcloudForm, setNextcloudForm] = useState({ server_url: 'https://cloud.4rb.nl/apps/calendar/dayGridMonth/now', username: '', password: '' });
    const [selectedNextcloudCalendar, setSelectedNextcloudCalendar] = useState('');
    const connect = () => { window.location.href = '/settings/calendar/outlook/redirect'; };
    const sync = () => router.post('/settings/calendar/outlook/sync', {}, { preserveScroll: true });
    const disconnect = () => { if (confirm(t('calendar.disconnect_confirm'))) router.delete('/settings/calendar/outlook', { preserveScroll: true }); };
    const choose = (e: React.FormEvent) => { e.preventDefault(); if (selectedCalendar) router.post('/settings/calendar/outlook/select', { calendar_id: selectedCalendar }, { preserveScroll: true }); };
    const discoverNextcloud = (e: React.FormEvent) => { e.preventDefault(); router.post('/settings/calendar/nextcloud/discover', nextcloudForm, { preserveScroll: true }); };
    const chooseNextcloud = (e: React.FormEvent) => { e.preventDefault(); if (selectedNextcloudCalendar) router.post('/settings/calendar/nextcloud/select', { calendar_id: selectedNextcloudCalendar }, { preserveScroll: true }); };
    const disconnectNextcloud = () => { if (confirm(t('calendar.disconnect_confirm'))) router.delete('/settings/calendar/nextcloud', { preserveScroll: true }); };

    return (
        <AppLayout breadcrumbs={[{ title: t('settings.calendar'), href: '/settings/calendar' }]}>
            <Head title={t('settings.calendar')} />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title={t('settings.calendar')} description={t('calendar.outlook_desc')} />
                    {!configured && <p className="text-muted-foreground text-sm">{t('calendar.outlook_not_configured')}</p>}
                    {pendingCalendars.length > 0 && (
                        <form className="border-border space-y-4 rounded-xl border p-4" onSubmit={choose}>
                            <div className="flex items-center gap-2 font-medium"><Calendar className="size-5" />{t('calendar.choose_outlook')}</div>
                            <select value={selectedCalendar} onChange={(e) => setSelectedCalendar(e.target.value)} required className="border-input bg-background h-10 w-full rounded-md border px-3 text-sm">
                                <option value="" disabled>{t('common.select')}</option>
                                {pendingCalendars.map((calendar) => <option key={calendar.id} value={calendar.id}>{calendar.name}</option>)}
                            </select>
                            <Button type="submit"><CheckCircle2 className="size-4" />{t('calendar.save_connection')}</Button>
                        </form>
                    )}
                    {!integration && pendingCalendars.length === 0 && configured && <Button onClick={connect}><Link2 className="size-4" />{t('calendar.connect_outlook')}</Button>}
                    {integration && (
                        <div className="border-border space-y-4 rounded-xl border p-4">
                            <div className="flex items-start gap-3"><Calendar className="text-muted-foreground mt-0.5 size-5" /><div><div className="font-medium">{integration.calendarName}</div><div className="text-muted-foreground text-sm">{integration.email}</div></div></div>
                            <div className="text-sm">{integration.status === 'active' ? t('calendar.connection_active') : t('calendar.connection_error')}</div>
                            {integration.lastError && <p className="text-destructive text-sm">{integration.lastError}</p>}
                            <div className="flex flex-wrap gap-2"><Button onClick={sync}><RefreshCw className="size-4" />{t('calendar.sync_now')}</Button><Button variant="outline" onClick={connect}><Link2 className="size-4" />{t('calendar.change_calendar')}</Button><Button variant="ghost" onClick={disconnect}><Unlink className="size-4" />{t('calendar.disconnect')}</Button></div>
                        </div>
                    )}
                    <div className="border-border space-y-4 rounded-xl border p-4">
                        <div className="flex items-start gap-3"><Calendar className="text-muted-foreground mt-0.5 size-5" /><div><div className="font-medium">Nextcloud</div><div className="text-muted-foreground text-sm">{t('calendar.nextcloud_desc')}</div></div></div>
                        {pendingNextcloudCalendars.length > 0 ? (
                            <form className="space-y-4" onSubmit={chooseNextcloud}>
                                <select value={selectedNextcloudCalendar} onChange={(e) => setSelectedNextcloudCalendar(e.target.value)} required className="border-input bg-background h-10 w-full rounded-md border px-3 text-sm"><option value="" disabled>{t('common.select')}</option>{pendingNextcloudCalendars.map((calendar) => <option key={calendar.id} value={calendar.id}>{calendar.name}</option>)}</select>
                                <Button type="submit"><CheckCircle2 className="size-4" />{t('calendar.save_connection')}</Button>
                            </form>
                        ) : nextcloud ? (
                            <><div className="text-sm">{nextcloud.calendarName} · {nextcloud.username}</div>{nextcloud.lastError && <p className="text-destructive text-sm">{nextcloud.lastError}</p>}<div className="flex flex-wrap gap-2"><Button onClick={() => router.post('/settings/calendar/nextcloud/sync', {}, { preserveScroll: true })}><RefreshCw className="size-4" />{t('calendar.sync_now')}</Button><Button variant="ghost" onClick={disconnectNextcloud}><Unlink className="size-4" />{t('calendar.disconnect')}</Button></div></>
                        ) : (
                            <form className="space-y-3" onSubmit={discoverNextcloud}>
                                <input required type="url" value={nextcloudForm.server_url} onChange={(e) => setNextcloudForm({ ...nextcloudForm, server_url: e.target.value })} placeholder="https://cloud.example.com/apps/calendar/dayGridMonth/now" className="border-input bg-background h-10 w-full rounded-md border px-3 text-sm" />
                                <input required value={nextcloudForm.username} onChange={(e) => setNextcloudForm({ ...nextcloudForm, username: e.target.value })} placeholder={t('calendar.nextcloud_username')} className="border-input bg-background h-10 w-full rounded-md border px-3 text-sm" />
                                <input required type="password" value={nextcloudForm.password} onChange={(e) => setNextcloudForm({ ...nextcloudForm, password: e.target.value })} placeholder={t('calendar.nextcloud_app_password')} className="border-input bg-background h-10 w-full rounded-md border px-3 text-sm" />
                                <Button type="submit"><Link2 className="size-4" />{t('calendar.connect_nextcloud')}</Button>
                            </form>
                        )}
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
