import {
    Apple,
    Baby,
    Bath,
    Beef,
    Bolt,
    Box,
    Candy,
    Carrot,
    Coffee,
    Cookie,
    Croissant,
    CupSoda,
    Dog,
    Drill,
    Droplets,
    Egg,
    Fish,
    Flower,
    Gift,
    Ham,
    Hammer,
    HeartPulse,
    Home,
    Leaf,
    Milk,
    Package,
    PaintRoller,
    PartyPopper,
    Pill,
    Plug,
    Ruler,
    Shirt,
    ShoppingBag,
    ShoppingBasket,
    ShoppingCart,
    Shovel,
    Snowflake,
    Sparkles,
    SprayCan,
    Sprout,
    Star,
    Store,
    Tablets,
    Tag,
    Wheat,
    Wine,
    Wrench,
    Zap,
    type LucideIcon,
} from 'lucide-react';

// Global aisle icon pack: aisles are per-shop now (see the aisle editor), and each stores an icon
// SLUG from this set. The pack is the single source of truth mapping that slug to a lucide icon, and
// AISLE_ICON_KEYS is the palette the editor's icon picker offers.
export const AISLE_ICONS: Record<string, LucideIcon> = {
    basket: ShoppingBasket,
    carrot: Carrot,
    milk: Milk,
    croissant: Croissant,
    package: Package,
    'spray-can': SprayCan,
    snowflake: Snowflake,
    beef: Beef,
    'cup-soda': CupSoda,
    apple: Apple,
    fish: Fish,
    egg: Egg,
    wheat: Wheat,
    coffee: Coffee,
    wine: Wine,
    cookie: Cookie,
    candy: Candy,
    ham: Ham,
    wrench: Wrench,
    drill: Drill,
    hammer: Hammer,
    shovel: Shovel,
    sprout: Sprout,
    droplets: Droplets,
    zap: Zap,
    'paint-roller': PaintRoller,
    bolt: Bolt,
    ruler: Ruler,
    plug: Plug,
    pill: Pill,
    tablets: Tablets,
    baby: Baby,
    bath: Bath,
    sparkles: Sparkles,
    'heart-pulse': HeartPulse,
    shirt: Shirt,
    'shopping-bag': ShoppingBag,
    gift: Gift,
    flower: Flower,
    leaf: Leaf,
    dog: Dog,
    home: Home,
    star: Star,
    box: Box,
    tag: Tag,
};

export const aisleIcon = (slug: string): LucideIcon => AISLE_ICONS[slug] ?? ShoppingBasket;
export const AISLE_ICON_KEYS = Object.keys(AISLE_ICONS);

// Per-list icon (chosen on the New List screen). Default 'cart'.
export const LIST_ICONS: Record<string, LucideIcon> = {
    cart: ShoppingCart,
    party: PartyPopper,
    tools: Wrench,
    gift: Gift,
    home: Home,
    store: Store,
};

export const listIcon = (slug: string) => LIST_ICONS[slug] ?? ShoppingCart;

// Per-list colour swatch → tile classes (bg + icon colour) for the list card and detail header.
export const LIST_COLORS: Record<string, string> = {
    green: 'bg-accent-subtle text-primary',
    amber: 'bg-amber-100 text-amber-700',
    red: 'bg-red-100 text-red-700',
    blue: 'bg-blue-100 text-blue-700',
    purple: 'bg-purple-100 text-purple-700',
};

export const listColor = (slug: string) => LIST_COLORS[slug] ?? LIST_COLORS.green;

// Voice dictation language. The Web Speech API can't auto-detect the spoken language, so the user
// picks one on the module Settings page. Stored per-device (it's a mic/input preference), default
// Romanian. Shared here so both the Settings page and the voice screen agree on the key + options.
export const VOICE_LANGS = [
    { code: 'ro-RO', label: 'Română' },
    { code: 'en-US', label: 'English' },
];
const VOICE_LANG_KEY = 'onsplan:voiceLang';
export const getVoiceLang = (): string => (typeof window === 'undefined' ? 'ro-RO' : localStorage.getItem(VOICE_LANG_KEY) || 'ro-RO');
export const setVoiceLang = (code: string): void => {
    try {
        localStorage.setItem(VOICE_LANG_KEY, code);
    } catch {
        /* private mode: fall back to session default */
    }
};

// "Bump" for the duplicate-item warning: the next quantity for an item already on the list. Leading
// number +1 ("2" -> "3", "1 gallon" -> "2 gallon"); empty means one, so it becomes "2"; free text with no
// leading number ("some") can't be bumped and returns null (the button is hidden).
export const bumpQuantity = (q: string | null | undefined): string | null => {
    const cur = (q ?? '').trim();
    if (cur === '') return '2';
    const m = /^(\d+)(.*)$/.exec(cur);
    return m ? String(Number(m[1]) + 1) + m[2] : null;
};

// Mirror of ShoppingItem::normalizeName (PHP): lower-case, whitespace collapsed, surrounding punctuation
// dropped. Used only to find the existing row the server's duplicate warning refers to (for "Bump").
export const normalizeItemName = (name: string): string =>
    name
        .trim()
        .toLowerCase()
        .replace(/\s+/g, ' ')
        .replace(/^[\p{P}\p{S}\s]+|[\p{P}\p{S}\s]+$/gu, '');
