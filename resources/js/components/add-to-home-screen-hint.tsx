import { Share, X } from 'lucide-react';
import { useEffect, useState } from 'react';

// One-time, dismissible nudge shown to iOS Safari users who haven't installed the PWA yet.
// iOS has no beforeinstallprompt event, so installing is a manual Share -> Add to Home Screen;
// this just points people at it. Hidden once installed (standalone) or once dismissed.
const DISMISS_KEY = 'fw-a2hs-dismissed';

export function AddToHomeScreenHint() {
    const [show, setShow] = useState(false);

    useEffect(() => {
        const ua = window.navigator.userAgent;
        const isIOS = /iphone|ipad|ipod/i.test(ua);
        const isStandalone =
            ('standalone' in window.navigator && (window.navigator as { standalone?: boolean }).standalone) ||
            window.matchMedia('(display-mode: standalone)').matches;
        if (!isIOS || isStandalone || localStorage.getItem(DISMISS_KEY)) return;
        // Let the page settle before nudging.
        const t = setTimeout(() => setShow(true), 1500);
        return () => clearTimeout(t);
    }, []);

    if (!show) return null;

    const dismiss = () => {
        localStorage.setItem(DISMISS_KEY, '1');
        setShow(false);
    };

    return (
        <div className="pointer-events-none fixed inset-x-0 bottom-[calc(5rem+env(safe-area-inset-bottom))] z-50 flex justify-center px-4 md:hidden">
            <div className="bg-surface-dark text-text-inverse pointer-events-auto flex w-full max-w-md items-center gap-3 rounded-2xl p-3 pr-2 shadow-[var(--shadow-fw-lg)]">
                <span className="bg-white/10 flex size-9 shrink-0 items-center justify-center rounded-xl">
                    <Share className="size-4.5" />
                </span>
                <p className="flex-1 text-sm leading-snug">
                    Add onsplan to your Home Screen: tap <Share className="mb-0.5 inline size-3.5" aria-label="Share" /> then{' '}
                    <span className="font-semibold">Add to Home Screen</span>.
                </p>
                <button onClick={dismiss} aria-label="Dismiss" className="shrink-0 rounded-lg p-2 text-white/70 hover:text-white">
                    <X className="size-4" />
                </button>
            </div>
        </div>
    );
}
