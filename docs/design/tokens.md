# onsplan Design Tokens

Source of truth for the brand: [`onsplan.pen`](./onsplan.pen) (Pen "Brand Identity Board").
These tokens are implemented as the default theme in the P1 plan (`resources/themes/default/theme.json`)
and surfaced at runtime as CSS custom properties. See spec §5 (Theming).

## Brand

- **Wordmark:** onsplan
- **Logomark:** leaf (lucide `leaf`), accent fill, full radius
- **Tagline:** "Your family, beautifully organized."
- **Feel:** calm, natural, wellness. Cream + sage/forest green.

## Colors

| Token | Hex | Role |
|---|---|---|
| `bg` | `#F5F3EE` | Page background (cream) |
| `surface` | `#E8E4DC` | Raised surface (sand) |
| `surface-card` | `#FFFFFF` | Cards |
| `surface-dark` | `#1B3A28` | Dark surface (forest) |
| `text-primary` | `#1B3A28` | Primary text |
| `text-secondary` | `#4A6B52` | Secondary text |
| `text-muted` | `#7A9A80` | Muted text |
| `text-inverse` | `#FFFFFF` | Text on dark/accent |
| `accent` | `#2D5E3A` | Primary accent (sage/forest) |
| `accent-light` | `#C8DBBC` | Light accent |
| `accent-subtle` | `#E6F0E0` | Subtle accent wash |
| `border` | `#D4D0C8` | Borders/dividers |
| `error` | `#C2453A` | Error/destructive |
| `warning` | `#D4943A` | Warning |
| `success` | `#2D5E3A` | Success (same as accent) |

## Typography

| Token | Family | Use |
|---|---|---|
| `font-heading` | Playfair Display | Headings, wordmark |
| `font-body` | Inter | Body, UI |
| `font-mono` | Geist Mono | Code, mono |

Fonts are self-hosted (spec §5) so themes work offline and on self-host.

## Spacing (px)

`xs` 4 · `sm` 8 · `md` 16 · `lg` 24 · `xl` 32 · `2xl` 48

## Radius (px)

`sm` 6 · `md` 12 · `lg` 16 · `full` 9999

## CSS variable naming

Tokens flatten to `--<name>` (colors), `--font-<name>`, `--spacing-<name>`, `--radius-<name>`,
e.g. `--accent`, `--text-primary`, `--font-heading`, `--spacing-md`, `--radius-md`.
Swapping a theme swaps this variable set at runtime, no rebuild.
