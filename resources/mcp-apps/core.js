// Shared runtime for the MCP Apps widgets (App\Mcp\Apps\Widget). Sits on laravel/mcp's inlined SDK
// (window.createMcpApp), which speaks the host's postMessage JSON-RPC. A widget file only calls
// FW.start(render): render(data, args) returns a DOM node for the tool result `data` (the same JSON the
// model sees) and the tool call's `args`. Plain DOM, no framework: each widget is a few dozen lines and
// a bundler would be the bigger thing to maintain.
//
// Changes go through our own write tools (FW.call), so the server enforces scopes and sharing exactly
// as for the assistant. The UI updates optimistically and reverts with a toast if the call fails.
// eslint-disable-next-line no-redeclare, @typescript-eslint/no-unused-vars -- the global every widget file uses
const FW = (() => {
    const root = document.getElementById('app');
    let app = null;
    let args = {};
    let data = null;
    let render = null;
    let toastTimer = null;
    // Display mode (MCP Apps spec): 'inline' in the chat or 'fullscreen'. canFullscreen = this widget opted
    // in (FW.start option, declared to the host by Widget::sdk) AND the host offers it.
    let mode = 'inline';
    let canFullscreen = false;

    // h('li', {class: 'x', onclick: fn}, child, 'text', [more]) -> Element. Text is always a text node,
    // never HTML, so nothing from the server can inject markup.
    function h(tag, props, ...kids) {
        const el = document.createElement(tag);
        for (const [k, v] of Object.entries(props || {})) {
            if (v === null || v === undefined || v === false) continue;
            if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
            else if (k === 'checked' || k === 'disabled' || k === 'value') el[k] = v;
            else el.setAttribute(k, v === true ? '' : v);
        }
        for (const kid of kids.flat(Infinity)) {
            if (kid === null || kid === undefined || kid === false) continue;
            el.append(kid instanceof Node ? kid : document.createTextNode(String(kid)));
        }
        return el;
    }

    // A tools/call result -> its JSON payload. Our tools answer with one JSON text block (Response::json).
    function payload(result) {
        if (!result) throw new Error('No response.');
        const text = (result.content || [])
            .filter((c) => c.type === 'text')
            .map((c) => c.text)
            .join('\n');
        if (result.isError) throw new Error(text || 'Something went wrong.');
        // Our tools send the payload twice: structuredContent (preferred) and the same JSON as text.
        if (result.structuredContent) return result.structuredContent;
        try {
            return JSON.parse(text);
        } catch {
            // Say what the host handed us instead of dumping it: a raw JSON wall is unreadable, and these
            // facts (shape, size, how it ends) are what tells a truncated or rewrapped result apart.
            const types = (result.content || []).map((c) => c.type).join(',') || 'none';
            throw new Error(
                `Couldn't read onsplan's answer (blocks: ${types}; ${text.length} chars; ends: "${text.slice(-30)}"; keys: ${Object.keys(result).join(',')}).`,
            );
        }
    }

    function draw() {
        if (!render || data === null) return;
        // A full redraw replaces the quick-add field; keep typing flow by refocusing it if it had focus.
        const refocus = !!document.activeElement?.closest?.('.add');
        try {
            root.replaceChildren(render(data, args));
            if (refocus) root.querySelector('.add input')?.focus();
        } catch (e) {
            root.replaceChildren(h('p', { class: 'danger pad' }, 'Could not show this: ' + e.message));
        }
    }

    function toast(msg) {
        document.querySelector('.toast')?.remove();
        document.body.append(h('div', { class: 'toast', role: 'status' }, msg));
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => document.querySelector('.toast')?.remove(), 4000);
    }

    // A write tool that isn't registered for this token means the connection is read-only for that module
    // (App\Mcp\Tool::shouldRegister hides it): say so in words people can act on.
    function friendly(err) {
        const msg = err?.message || String(err);
        return /tool .*not found/i.test(msg) ? 'This connection can only read here. Reconnect onsplan with "Read & write" to make changes.' : msg;
    }

    async function call(name, a) {
        return payload(await app.callServerTool(name, a || {}));
    }

    // Optimistic change: apply() mutates state and we redraw now; the tool call runs; on failure revert()
    // undoes it, we redraw and explain. Returns the tool's payload (or null on failure).
    async function act({ apply, revert, tool, args: a, tell }) {
        // Only redraw for an optimistic change: a redraw also replaces the quick-add field mid-save.
        if (apply) {
            apply();
            draw();
        }
        try {
            const out = await call(tool, a);
            if (tell) note(tell);
            return out;
        } catch (e) {
            if (revert) {
                revert();
                draw();
            }
            toast(friendly(e));
            return null;
        }
    }

    // Re-run the tool that produced this widget (same args) and redraw, e.g. after adding something.
    async function reload(tool) {
        try {
            data = await call(tool, args);
            draw();
        } catch (e) {
            toast(friendly(e));
        }
    }

    // Tell the model what the person did in the widget, so the next turn knows ("ticked Milk").
    // Each update replaces the previous one (spec), so keep a short running log. Optional on hosts.
    const log = [];
    function note(text) {
        log.push(text);
        app.updateModelContext({
            content: [{ type: 'text', text: 'In the onsplan widget the person: ' + log.slice(-20).join('; ') + '.' }],
        }).catch(() => {});
    }

    function open(url) {
        app.openLink(url).catch(() => window.open(url, '_blank', 'noopener'));
    }

    // Formatting in the host's locale/timezone when given.
    let locale;

    // Hosts spell locales their own way (ChatGPT sends "en_RO"), but Intl only takes BCP 47 ("en-RO") and
    // throws "invalid language tag" otherwise, which broke every widget's dates. Normalise, and fall back
    // to the browser default (undefined) when it still isn't valid.
    function toLocale(raw) {
        if (!raw || typeof raw !== 'string') return undefined;
        try {
            return Intl.getCanonicalLocales(raw.replace(/_/g, '-'))[0];
        } catch {
            return undefined;
        }
    }
    const dayMs = 86400000;
    function today() {
        const d = new Date();
        return new Date(d.getFullYear(), d.getMonth(), d.getDate());
    }
    function parseDay(iso) {
        const [y, m, d] = String(iso).slice(0, 10).split('-').map(Number);
        return new Date(y, m - 1, d);
    }
    // "Today", "Tomorrow", "Yesterday", "Mon 6 Oct", "6 Oct 2027".
    function day(iso) {
        if (!iso) return '';
        const d = parseDay(iso);
        const diff = Math.round((d - today()) / dayMs);
        if (diff === 0) return 'Today';
        if (diff === 1) return 'Tomorrow';
        if (diff === -1) return 'Yesterday';
        const sameYear = d.getFullYear() === today().getFullYear();
        return d.toLocaleDateString(
            locale,
            sameYear ? { weekday: 'short', day: 'numeric', month: 'short' } : { day: 'numeric', month: 'short', year: 'numeric' },
        );
    }
    function overdue(iso) {
        return !!iso && parseDay(iso) < today();
    }
    function money(amount, currency) {
        const n = Number(amount);
        try {
            return n.toLocaleString(locale, { style: 'currency', currency: currency || 'EUR' });
        } catch {
            return n.toFixed(2) + (currency ? ' ' + currency : '');
        }
    }
    function num(n, opts) {
        return Number(n).toLocaleString(locale, opts);
    }

    // Header: actions row (Full screen, Open in onsplan) on TOP, then the title at full width, then the
    // metadata line. Hosts render widgets ~360px wide on phones; with the actions beside the title, the
    // title got squeezed to one word per line (seen on the recipe view in ChatGPT on iPhone).
    // opts.hero: an element (a photo) shown full width between the actions row and the title.
    function head(title, sub, url, opts = {}) {
        const actions = [
            canFullscreen
                ? h(
                      'button',
                      { class: 'link', type: 'button', onclick: toggleFullscreen },
                      mode === 'fullscreen' ? 'Exit full screen' : 'Full screen ⤢',
                  )
                : null,
            url ? h('button', { class: 'link', type: 'button', onclick: () => open(url) }, 'Open in onsplan ↗') : null,
        ].filter(Boolean);
        return h(
            'div',
            { class: 'head' },
            actions.length ? h('div', { class: 'head-actions' }, actions) : null,
            opts.hero || null,
            h('h1', null, title),
            sub ? h('div', { class: 'sub' }, sub) : null,
        );
    }

    // ponytail: measured from Claude's iOS app (status bar + close button on top, reply box at the bottom),
    // which drew full screen widgets under its chrome. Only used as a floor on touch devices in full screen;
    // a host that reports larger safeAreaInsets wins.
    const TOUCH_FULLSCREEN_FLOOR = { top: 112, right: 0, bottom: 140, left: 0 };

    // Display mode + safe-area insets from the (merged) host context.
    function applyContext(c) {
        mode = c.displayMode || 'inline';
        const root = document.documentElement;
        root.classList.toggle('fs', mode === 'fullscreen');
        // The skin's dark tokens are scoped to .dark (ThemeTokens::css), like in the app.
        if (c.theme) root.classList.toggle('dark', c.theme === 'dark');
        const touch = c.platform === 'mobile' || c.deviceCapabilities?.touch || matchMedia('(pointer: coarse)').matches;
        const floor = mode === 'fullscreen' && touch ? TOUCH_FULLSCREEN_FLOOR : {};
        for (const side of ['top', 'right', 'bottom', 'left']) {
            const px = Math.max(Number(c.safeAreaInsets?.[side]) || 0, floor[side] || 0);
            root.style.setProperty(`--fw-inset-${side}`, px + 'px');
        }
    }

    // The host decides: it answers with the mode actually set, which may not be the one we asked for.
    async function toggleFullscreen() {
        try {
            const res = await app.requestDisplayMode(mode === 'fullscreen' ? 'inline' : 'fullscreen');
            applyContext({ ...app.getHostContext(), displayMode: res?.mode });
            draw();
        } catch (e) {
            toast(friendly(e));
        }
    }

    // Quick-add field; onAdd(text) resolves when saved. Deliberately NOT a <form>: hosts sandbox the
    // iframe and may omit allow-forms, which blocks the submit event outright. Enter or the button adds.
    function addForm(placeholder, onAdd) {
        const input = h('input', { type: 'text', placeholder, 'aria-label': placeholder, maxlength: 255 });
        const btn = h('button', { class: 'btn', type: 'button' }, 'Add');
        const submit = async () => {
            const text = input.value.trim();
            if (!text || btn.disabled) return;
            btn.disabled = true;
            try {
                await onAdd(text);
                input.value = '';
            } finally {
                btn.disabled = false;
            }
        };
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.isComposing) {
                e.preventDefault();
                submit();
            }
        });
        btn.addEventListener('click', submit);
        return h('div', { class: 'add' }, input, btn);
    }

    // The app's dropdown (resources/js/components/picker.tsx), for widgets: a control button that expands
    // INLINE into a search box on top + a listbox, never a native <select> (one look across the app and the
    // widgets, and the inline list makes the iframe grow instead of a host-clipped native popup).
    // Behaves like a <select> for callers: `el.value` is the chosen value and a `change` event fires.
    // options: [{ value, label }]. opts: { value, label (aria), placeholder }.
    let openPicker = null; // one open at a time; a tap outside closes it
    const outside = (e) => {
        if (openPicker && !openPicker.el.contains(e.target)) openPicker.close();
    };
    // lucide "search" (the icon the app's Picker shows), built as SVG since h() makes HTML elements only.
    function searchIcon() {
        const ns = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(ns, 'svg');
        for (const [k, v] of Object.entries({
            viewBox: '0 0 24 24',
            width: '16',
            height: '16',
            fill: 'none',
            stroke: 'currentColor',
            'stroke-width': '2',
            'stroke-linecap': 'round',
            'aria-hidden': 'true',
        }))
            svg.setAttribute(k, v);
        const circle = document.createElementNS(ns, 'circle');
        circle.setAttribute('cx', '11');
        circle.setAttribute('cy', '11');
        circle.setAttribute('r', '8');
        const path = document.createElementNS(ns, 'path');
        path.setAttribute('d', 'm21 21-4.3-4.3');
        svg.append(circle, path);
        return svg;
    }
    function picker(options, opts = {}) {
        let value = opts.value !== undefined ? String(opts.value) : options[0] ? String(options[0].value) : '';
        const labelOf = (v) => options.find((o) => String(o.value) === v)?.label ?? opts.placeholder ?? 'Select';
        const current = h('span', { class: 'picker-label' }, labelOf(value));
        const control = h(
            'button',
            { class: 'picker-ctl', type: 'button', 'aria-haspopup': 'listbox', 'aria-expanded': 'false', 'aria-label': opts.label },
            current,
            h('span', { class: 'picker-chev', 'aria-hidden': 'true' }, '▾'),
        );
        const el = h('div', { class: 'picker' }, control);
        let pop = null;

        const close = (focus = false) => {
            pop?.remove();
            pop = null;
            control.setAttribute('aria-expanded', 'false');
            if (openPicker?.el === el) openPicker = null;
            document.removeEventListener('click', outside);
            if (focus) control.focus();
        };
        const choose = (v) => {
            const changed = v !== value;
            value = v;
            current.textContent = labelOf(v);
            close(true);
            if (changed) el.dispatchEvent(new Event('change'));
        };
        const open = () => {
            openPicker?.close();
            const search = h('input', { type: 'text', placeholder: 'Search…', 'aria-label': 'Search' });
            const list = h('div', { class: 'picker-list', role: 'listbox' });
            const fill = () => {
                const q = search.value.trim().toLowerCase();
                const shown = options.filter((o) => !q || String(o.label).toLowerCase().includes(q));
                list.replaceChildren(
                    ...(shown.length
                        ? shown.map((o) => {
                              const on = String(o.value) === value;
                              return h(
                                  'button',
                                  {
                                      class: 'picker-opt',
                                      type: 'button',
                                      role: 'option',
                                      'aria-selected': String(on),
                                      onclick: () => choose(String(o.value)),
                                  },
                                  h('span', { class: 'picker-name' }, o.label),
                                  on ? h('span', { class: 'picker-check', 'aria-hidden': 'true' }, '✓') : null,
                              );
                          })
                        : [h('div', { class: 'picker-empty' }, 'No matches')]),
                );
            };
            search.addEventListener('input', fill);
            // The search box's own native `change` would bubble to the picker and look like a pick to callers.
            search.addEventListener('change', (e) => e.stopPropagation());
            fill();
            pop = h('div', { class: 'picker-pop' }, h('div', { class: 'picker-search' }, searchIcon(), search), list);
            el.append(pop);
            control.setAttribute('aria-expanded', 'true');
            openPicker = { el, close };
            document.addEventListener('click', outside);
            search.focus({ preventScroll: true });
        };
        control.addEventListener('click', () => (pop ? close() : open()));
        el.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && pop) {
                e.stopPropagation();
                close(true);
            }
        });
        Object.defineProperty(el, 'value', { get: () => value });
        return el;
    }

    // opts.fullscreen: offer a full-screen button (needs the widget in Widget::FULLSCREEN too).
    function start(fn, opts = {}) {
        render = fn;
        window.createMcpApp(async (a) => {
            app = a;
            const ctx = a.getHostContext() || {};
            locale = toLocale(ctx.locale);
            canFullscreen = !!opts.fullscreen && (ctx.availableDisplayModes || []).includes('fullscreen');
            applyContext(ctx);
            // The host reports changes it makes itself too: mode (its own close button, Esc), insets, rotation.
            // The SDK passes the merged context.
            a.onHostContextChanged((c) => {
                if (c.locale) locale = toLocale(c.locale);
                const before = mode;
                applyContext(c);
                if (mode !== before) draw();
            });
            a.onToolInput((p) => {
                args = p?.arguments || {};
            });
            a.onToolResult((result) => {
                try {
                    data = payload(result);
                    draw();
                } catch (e) {
                    root.replaceChildren(h('p', { class: 'danger pad' }, friendly(e)));
                }
            });
            a.onToolCancelled(() => root.replaceChildren(h('p', { class: 'muted pad' }, 'Cancelled.')));
            a.autoResize();
        });
    }

    return {
        h,
        toLocale,
        start,
        draw,
        call,
        act,
        reload,
        note,
        toast,
        friendly,
        open,
        day,
        overdue,
        money,
        num,
        head,
        addForm,
        picker,
        get data() {
            return data;
        },
        set data(v) {
            data = v;
        },
    };
})();
