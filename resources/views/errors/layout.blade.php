@php
    // Blade-only on purpose: error pages must render even when the JS bundle is broken (that is often
    // *why* you are seeing one). We reuse the exact theme tokens so it matches the app, with a hardcoded
    // fallback in case the theme file itself is what failed.
    try {
        $themeCss = $themeCss ?? \App\Support\Theming\ThemeTokens::css('default');
    } catch (\Throwable $e) {
        $themeCss = ':root{--bg:#F5F3EE;--surface-card:#FFFFFF;--text-primary:#1B3A28;--text-secondary:#4A6B52;--text-muted:#7A9A80;--primary:#2D5E3A;--text-inverse:#FFFFFF;--border:#D4D0C8;}';
    }
    // Authenticated users go back to their dashboard; guests to the public home. url('/') routes both.
    $home = auth()->check() ? url('/dashboard') : url('/');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>@yield('title') · onsplan</title>
    <meta name="theme-color" content="#2D5E3A">
    <style id="theme">{!! $themeCss !!}</style>
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{
            min-height:100dvh;background:var(--bg);color:var(--text-primary);
            font-family:'Inter',system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
            display:flex;align-items:center;justify-content:center;
            padding:24px calc(24px + env(safe-area-inset-right)) calc(24px + env(safe-area-inset-bottom)) calc(24px + env(safe-area-inset-left));
        }
        .card{
            width:100%;max-width:26rem;background:var(--surface-card);
            border:1px solid var(--border);border-radius:var(--radius-lg,16px);
            padding:40px 32px;text-align:center;
        }
        .brand{display:inline-flex;align-items:center;gap:8px;margin-bottom:28px}
        .brand .mark{
            display:flex;align-items:center;justify-content:center;
            width:32px;height:32px;border-radius:8px;background:var(--primary);
        }
        .brand .mark svg{width:20px;height:20px;fill:var(--text-inverse)}
        .brand .name{font-size:18px;font-weight:600;color:var(--primary)}
        .code{font-size:13px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--text-muted)}
        h1{
            font-family:'Playfair Display',Georgia,'Times New Roman',serif;
            font-size:30px;line-height:1.15;margin:10px 0 12px;color:var(--text-primary)
        }
        p{color:var(--text-secondary);font-size:16px;line-height:1.5;margin-bottom:28px}
        .btn{
            display:inline-block;background:var(--primary);color:var(--text-inverse);
            text-decoration:none;font-weight:600;font-size:15px;
            padding:13px 24px;border-radius:9999px;
        }
        .btn:hover{opacity:.92}
    </style>
</head>
<body>
    <main class="card">
        <span class="brand">
            <span class="mark">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M17 8C8 10 5.9 16.17 3.82 21.34l1.89.66.95-2.3c.48.17.98.3 1.34.3C19 20 22 3 22 3c-1 2-8 2.25-13 3.25S2 11.5 2 13.5s1.75 3.75 1.75 3.75C7 8 17 8 17 8z" />
                </svg>
            </span>
            <span class="name">onsplan</span>
        </span>

        <div class="code">@yield('code')</div>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>

        <a class="btn" href="{{ $home }}">@yield('cta', 'Back to home')</a>
    </main>
</body>
</html>
