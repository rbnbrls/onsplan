import * as React from 'react';

// After a deploy, an already-open (installed) PWA can still hold the old HTML, which points at page
// chunks whose hashes changed, so a navigation fails to import and React renders nothing = blank
// white screen. This boundary catches that: chunk/load errors auto-reload once (pulling fresh HTML +
// assets), and any other render error shows a Reload button instead of a dead blank page.

const RELOAD_FLAG = 'fw:reloaded-after-crash';

export function isChunkError(message?: string | null): boolean {
    return /dynamically imported module|module script failed|ChunkLoadError|Failed to fetch|Load failed/i.test(message ?? '');
}

// Reload the page at most once (guarded by sessionStorage so a persistent failure can't loop).
export function reloadOnceOnChunkError(message?: string | null): boolean {
    if (isChunkError(message) && !sessionStorage.getItem(RELOAD_FLAG)) {
        sessionStorage.setItem(RELOAD_FLAG, '1');
        window.location.reload();
        return true;
    }
    return false;
}

// Once the app has been alive a few seconds without crashing, clear the guard so a *future* deploy
// can auto-reload again.
export function armCrashRecovery(): void {
    window.addEventListener('unhandledrejection', (e) => reloadOnceOnChunkError((e.reason as Error)?.message ?? String(e.reason)));
    window.addEventListener('error', (e) => reloadOnceOnChunkError(e.message));
    setTimeout(() => sessionStorage.removeItem(RELOAD_FLAG), 4000);
}

// Best-effort: log the crash server-side so we can diagnose issues that only reproduce on a
// particular account/device (they never reach our test accounts otherwise).
function reportClientError(message: string, stack: string): void {
    try {
        const xsrf = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
        // Snapshot the app's own localStorage (theme, nav pins, sidebar, etc. — non-sensitive) so we
        // can diagnose crashes that only reproduce with a specific device's stored state.
        let storage = '';
        try {
            storage = JSON.stringify({ ...localStorage });
        } catch {
            /* ignore */
        }
        void fetch('/client-error', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                message,
                stack,
                url: window.location.pathname,
                storage,
                ua: navigator.userAgent,
                standalone: window.matchMedia('(display-mode: standalone)').matches,
            }),
        }).catch(() => {});
    } catch {
        /* ignore */
    }
}

// Wipe client-side state that could be keeping the app stuck (a poison localStorage value, a stale
// service worker / cache), then reload fresh.
async function resetAppData() {
    try {
        localStorage.clear();
        sessionStorage.clear();
    } catch {
        /* ignore */
    }
    try {
        if ('serviceWorker' in navigator) {
            const regs = await navigator.serviceWorker.getRegistrations();
            await Promise.all(regs.map((r) => r.unregister()));
        }
        if ('caches' in window) {
            const keys = await caches.keys();
            await Promise.all(keys.map((k) => caches.delete(k)));
        }
    } catch {
        /* ignore */
    }
    window.location.reload();
}

export class ErrorBoundary extends React.Component<{ children: React.ReactNode }, { failed: boolean; message: string }> {
    state = { failed: false, message: '' };

    static getDerivedStateFromError(error: unknown) {
        return { failed: true, message: error instanceof Error ? error.message : String(error) };
    }

    componentDidCatch(error: unknown, info: React.ErrorInfo) {
        const message = error instanceof Error ? error.message : String(error);
        if (reloadOnceOnChunkError(message)) return;

        console.error('App crash:', error, info.componentStack);
        reportClientError(message, ((error as Error)?.stack ?? '') + '\n' + (info.componentStack ?? ''));
    }

    render() {
        if (!this.state.failed) return this.props.children;
        return (
            <div className="bg-background flex min-h-dvh flex-col items-center justify-center gap-4 p-8 text-center">
                <p className="font-heading text-primary text-xl">Something went wrong</p>
                <p className="text-muted-foreground max-w-xs text-sm">The app hit a snag. Reloading usually fixes it.</p>
                <div className="flex flex-col items-center gap-2">
                    <button
                        type="button"
                        onClick={() => {
                            sessionStorage.removeItem(RELOAD_FLAG);
                            window.location.reload();
                        }}
                        className="bg-accent-strong text-accent-strong-foreground rounded-full px-5 py-2 text-sm font-medium"
                    >
                        Reload
                    </button>
                    {/* If a reload keeps landing back here, this clears any stuck local state and reloads. */}
                    <button type="button" onClick={() => void resetAppData()} className="text-muted-foreground text-xs underline">
                        Still stuck? Reset app data
                    </button>
                </div>
                {/* Small diagnostic line so a screenshot tells us the page + error. */}
                {this.state.message && (
                    <p className="text-muted-foreground/70 mt-4 max-w-xs font-mono text-[11px] break-words">
                        {window.location.pathname}: {this.state.message}
                    </p>
                )}
            </div>
        );
    }
}
