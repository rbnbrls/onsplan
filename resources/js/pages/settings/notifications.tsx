import { Head, usePage } from '@inertiajs/react';
import { Bell, BellOff, Send } from 'lucide-react';
import { useEffect, useState } from 'react';

import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { iosNeedsInstall, isSubscribed, pushSupported, savePushPrefs, sendTestPush, subscribeToPush, unsubscribeFromPush } from '@/lib/push';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Notifications', href: '/settings/notifications' }];

type Category = { id: string; label: string; icon: string; on: boolean };

export default function Notifications({ categories }: { categories: Category[] }) {
    const vapidPublicKey = (usePage().props as { vapidPublicKey?: string }).vapidPublicKey ?? '';
    // Category list + labels come from the server (each module registers its own).
    const [prefs, setPrefs] = useState<Record<string, boolean>>(() => Object.fromEntries(categories.map((c) => [c.id, c.on])));

    const togglePref = (key: string) => {
        const next = { ...prefs, [key]: !prefs[key] };
        setPrefs(next);
        void savePushPrefs(next);
    };
    const [supported, setSupported] = useState(true);
    const [needsInstall, setNeedsInstall] = useState(false);
    const [on, setOn] = useState(false);
    const [busy, setBusy] = useState(false);
    const [testState, setTestState] = useState<'idle' | 'sent'>('idle');

    useEffect(() => {
        setSupported(pushSupported());
        setNeedsInstall(iosNeedsInstall());
        void isSubscribed().then(setOn);
    }, []);

    const toggle = async () => {
        setBusy(true);
        try {
            if (on) {
                await unsubscribeFromPush();
                setOn(false);
            } else {
                setOn(await subscribeToPush(vapidPublicKey));
            }
        } finally {
            setBusy(false);
        }
    };

    const test = async () => {
        setTestState('idle');
        if (await sendTestPush()) {
            setTestState('sent');
            setTimeout(() => setTestState('idle'), 4000);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notifications" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Notifications"
                        description="Get a push on this device (Android, desktop, or an installed iPhone app) when things happen in your family."
                    />

                    {!supported ? (
                        <div className="text-muted-foreground rounded-xl border border-dashed p-4 text-sm">
                            This browser doesn't support push notifications.
                        </div>
                    ) : needsInstall ? (
                        <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                            On iPhone/iPad, add onsplan to your Home Screen first (Share → Add to Home Screen), then open it from there to turn on
                            notifications.
                        </div>
                    ) : (
                        <>
                            <div className="bg-surface-card flex items-center gap-3 rounded-xl border p-4">
                                <span
                                    className={`flex size-10 shrink-0 items-center justify-center rounded-lg ${on ? 'bg-accent-subtle text-primary' : 'bg-surface text-muted-foreground'}`}
                                >
                                    {on ? <Bell className="size-5" /> : <BellOff className="size-5" />}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="text-primary text-sm font-medium">Push on this device</div>
                                    <div className="text-muted-foreground text-xs">{on ? 'Enabled' : 'Off'}</div>
                                </div>
                                <Button variant={on ? 'outline' : 'default'} onClick={toggle} disabled={busy}>
                                    {busy ? 'Working…' : on ? 'Turn off' : 'Turn on'}
                                </Button>
                            </div>

                            {on && (
                                <Button variant="outline" onClick={test} className="gap-2">
                                    <Send className="size-4" />
                                    {testState === 'sent' ? 'Sent — check your notifications' : 'Send a test notification'}
                                </Button>
                            )}

                            <p className="text-muted-foreground text-xs leading-relaxed">
                                Push is per-device: turn it on wherever you want to be alerted. What you're notified about (below) is account-wide.
                            </p>
                        </>
                    )}

                    <div className="space-y-3 pt-2">
                        <div className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Notify me about</div>
                        <p className="text-muted-foreground text-xs">
                            Turning one off stops the phone push, but it still shows in your in-app notifications.
                        </p>
                        <div className="bg-surface-card divide-border/60 divide-y rounded-xl border">
                            {categories.map((c) => (
                                <div key={c.id} className="flex items-center justify-between px-4 py-3">
                                    <span className="text-primary text-sm font-medium">{c.label}</span>
                                    <button
                                        type="button"
                                        role="switch"
                                        aria-checked={prefs[c.id]}
                                        onClick={() => togglePref(c.id)}
                                        className={`relative h-6 w-10 shrink-0 rounded-full transition-colors ${prefs[c.id] ? 'bg-accent-strong' : 'bg-border'}`}
                                    >
                                        <span
                                            className={`absolute top-0.5 size-5 rounded-full bg-white shadow-sm transition-all ${prefs[c.id] ? 'left-[18px]' : 'left-0.5'}`}
                                        />
                                    </button>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
