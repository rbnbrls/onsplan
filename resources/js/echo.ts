import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo?: Echo<'reverb'>;
        __onsplanReverb?: { key: string; host: string; port: number; scheme: string };
        __onsplanFamilyId?: number;
    }
}

window.Pusher = Pusher;

// Reverb connection is injected at runtime (server env -> window), not baked into the bundle,
// so the same build works on any host/deployment.
const cfg = window.__onsplanReverb;
if (cfg && cfg.key) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: cfg.key,
        wsHost: cfg.host,
        wsPort: cfg.port,
        wssPort: cfg.port,
        forceTLS: cfg.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        // Heartbeat below a typical PaaS load-balancer idle timeout (spec 12.4).
        activityTimeout: 30000,
    });
}
