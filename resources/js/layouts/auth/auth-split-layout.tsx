import { AppWordmark } from '@/components/app-logo';
import AppLogoIcon from '@/components/app-logo-icon';
import { usePage } from '@inertiajs/react';

interface AuthLayoutProps {
    children: React.ReactNode;
    title?: string;
    description?: string;
    // Optional per-screen brand-panel overrides. Onboarding passes these (with the user's name);
    // auth pages omit them and fall back to the route-keyed PANELS map below.
    headline?: string;
    sub?: string;
    image?: string;
}

// Brand-panel content per auth screen (design: "Web · Auth Flow"). Keyed by route name so the pages
// stay focused on their forms; anything not listed (reset-password, verify-email, confirm-password)
// falls back to the calm login panel, keeping the whole auth surface consistent.
// The images are photos of cosy home interiors generated for onsplan (public/images/auth/*.webp,
// 1024x1536, ~150 KB): evening living room (login), morning kitchen (register), rainy reading nook (reset).
type PanelContent = { headline: string; sub: string; image: string };

const PANELS: Record<string, PanelContent> = {
    login: {
        headline: 'Where your family finds its rhythm.',
        sub: 'Calm, shared organization for the people who matter most.',
        image: '/images/auth/login.webp',
    },
    register: {
        headline: 'Start something calm together.',
        sub: 'Create a shared home for schedules, lists, and the little moments in between.',
        image: '/images/auth/register.webp',
    },
    'password.request': {
        headline: "We'll help you back in.",
        sub: 'Resetting your password only takes a moment.',
        image: '/images/auth/reset.webp',
    },
};

export default function AuthSplitLayout({ children, title, description, headline, sub, image }: AuthLayoutProps) {
    const { name } = usePage().props as { name?: string };
    // The outlined wordmark is the onsplan brand; a self-hosted instance with its own APP_NAME keeps its name as text.
    const isBrandName = !name || name.toLowerCase() === 'onsplan';
    // ziggy's current() gives the active route name; fall back to the login panel for undesigned screens.
    const routeName = typeof route().current === 'function' ? route().current() : undefined;
    const base = (routeName && PANELS[routeName]) || PANELS.login;
    // Explicit props win over the route map, so onboarding can inject the user's name per step.
    const panel = { headline: headline ?? base.headline, sub: sub ?? base.sub, image: image ?? base.image };

    return (
        <div className="grid min-h-dvh lg:grid-cols-2">
            {/* Brand panel: the photo fills the whole half (hidden below lg, where the form stands alone).
                Text sits on gradients, so it is fixed white in BOTH themes: theme tokens (text-inverse on
                surface-dark) both turned dark in dark mode, which made the wordmark and tagline unreadable. */}
            <div className="relative hidden overflow-hidden bg-neutral-900 lg:block">
                <img
                    src={panel.image}
                    alt=""
                    className="absolute inset-0 h-full w-full object-cover"
                    loading="eager"
                    onError={(e) => {
                        e.currentTarget.style.display = 'none';
                    }}
                />
                {/* Top and bottom scrims keep the wordmark and the tagline legible on any photo. */}
                <div className="absolute inset-x-0 top-0 h-40 bg-gradient-to-b from-black/55 to-transparent" />
                <div className="absolute inset-x-0 bottom-0 h-2/5 bg-gradient-to-t from-black/80 via-black/45 to-transparent" />

                <div className="relative flex h-full flex-col justify-between p-10">
                    {/* White-only logo (onsplan-Logo-White): no yellow tile over the photo. */}
                    {/* The logo leads to the public site (onsplan.nl): the app root only bounces guests back here. */}
                    <a href="https://onsplan.nl" className="flex items-center gap-2.5 text-white">
                        <AppLogoIcon className="size-7 fill-current" />
                        {isBrandName ? <AppWordmark className="h-6 w-auto fill-current" /> : <span className="font-heading text-2xl">{name}</span>}
                    </a>

                    <div className="flex max-w-md flex-col gap-3">
                        <h2 className="font-heading text-[34px] leading-tight text-white">{panel.headline}</h2>
                        <p className="text-[15px] leading-relaxed text-white/80">{panel.sub}</p>
                    </div>
                </div>
            </div>

            {/* Form side */}
            <div className="bg-background flex flex-col items-center justify-center p-6 sm:p-12">
                <div className="flex w-full max-w-[420px] flex-col gap-6">
                    {/* Wordmark shows on mobile where the brand panel is hidden. */}
                    <a href="https://onsplan.nl" className="text-primary flex items-center gap-2.5 lg:hidden">
                        <span className="flex size-8 items-center justify-center rounded-md bg-[#FFDF00]">
                            <AppLogoIcon className="size-5 fill-[#101100]" />
                        </span>
                        {isBrandName ? <AppWordmark className="h-6 w-auto fill-current" /> : <span className="font-heading text-2xl">{name}</span>}
                    </a>

                    {/* Auth pages pass title/description; onboarding renders its own header (eyebrow,
                        stepper) inside children so those sit above the title as the design shows. */}
                    {title && (
                        <div className="flex flex-col gap-2">
                            <h1 className="font-heading text-primary text-[32px] leading-tight">{title}</h1>
                            {description && <p className="text-muted-foreground text-[15px]">{description}</p>}
                        </div>
                    )}

                    {children}
                </div>
            </div>
        </div>
    );
}
