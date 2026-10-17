# onsplan Design System

onsplan is a calm, warm app for **organizing family life** — shared calendars, tasks, grocery/shopping lists, contacts, and family management, across a **mobile app**, a **responsive web app**, and **mobile web**. The brand voice is gentle and human; the visual world is earthy — forest greens on warm paper, serif display type, and soft nature illustrations. Tagline: *"Your family, beautifully organized."*

## Sources
- `uploads/onsplan.pen` — a **pencil.dev** design file (v2.14). The single source of truth: brand identity board, design tokens (its `variables` block), three explicit reusable components (`component/InputField`, `component/PrimaryButton`, `component/TopNav`), and ~40 product frames across Mobile app, Web, and Mobile-web.
- `design/images/*.png` — three brand illustrations, copied into `assets/img/` (`scene-1` sunset hillside walk, `scene-2` forest cottage, `scene-3` watercolor botanical).
- No logo file was supplied — see **Logo** below.

## Components
Reusable primitives (React, styled with CSS custom properties). Import from `window.OnsplanDesignSystem_c7911f`.
- **Button** — primary / secondary / ghost / danger; sm/md/lg. (source `PrimaryButton`)
- **IconButton** — single-icon control; subtle / surface / solid / ghost.
- **Input** — labelled text field with helper + error states. (source `InputField`)
- **Card** — white surface panel; `radius` md/lg, optional left `accentBar`.
- **Checkbox** — rounded checkbox from the task lists.
- **Avatar** — circular initial/image avatar with pastel family tints.
- **Badge** — status/count pill; subtle / solid / warning / error / neutral / outline.
- **TopNav** — web top navigation. (source `TopNav`)
- **TabBar** — mobile floating bottom navigation.

**Intentional additions:** the source explicitly marks only `InputField`, `PrimaryButton`, `TopNav` as reusable, but Card, Checkbox, Avatar, Badge, IconButton and TabBar recur on nearly every frame, so they are authored as primitives. `AVATAR_TINTS` is exported as a helper map.

## UI kits & templates
- `ui_kits/web-app/` — interactive Login → Dashboard → Shopping flow (`index.html`).
- `templates/mobile-app/` — **the full mobile app** as a clickable prototype template (`MobileApp.dc.html`): 12 iOS screens — Home, Widget Editor/Gallery/Size Picker, Grocery List Detail (+ menu & share overlays), Focus Mode, Add Item, Voice Dictation, AI Review, New List. State-driven navigation, checkable items, live quantity/progress. This is the canonical spec for how the whole experience should be built. Consuming projects copy the folder and repoint `ds-base.js`.
- `ui_kits/_shared/icons.jsx` — inline Lucide/Phosphor-style icon set shared by the kits.

## Fonts
- **Playfair Display** — headings, brand, numerals in stat cards.
- **Inter** — body and all UI text.
- **Geist Mono** — times, versions, quantities, metadata.

All three are Google Fonts (loaded via `tokens/fonts.css`). To fully self-host, drop `.woff2` files in `assets/fonts/` and replace the `@import` with local `@font-face` rules. Because they load via `@import` (not `@font-face`), the compiler reports "0 fonts" — this is expected.

---

## CONTENT FUNDAMENTALS
- **Voice:** warm, calm, plain-spoken — like a thoughtful family member, never a system or an enterprise tool. Reassuring, never urgent.
- **Person:** speaks *to* the user with light second person and their name ("Good afternoon, Maya"). Family members are named naturally (Mom, Dad, Emma, Leo; "The Johnsons", "The Rivera Family").
- **Casing:** sentence case everywhere — headings, buttons, labels. No ALL-CAPS except tiny mono spec labels on specimen cards. Titles that are proper nouns keep their caps ("Weekly Groceries", "Whole Foods").
- **Numbers & time:** friendly counts ("3 remaining", "5 open tasks", "12 items to buy"); times and quantities in Geist Mono ("9:00", "11:30", "2 gal").
- **Tone examples (do):** "Good morning — 3 things need your attention today." · "Where your family finds its rhythm." · "Calm, shared organization for the people who matter most." · "Sign in to keep your family in sync."
- **Avoid:** clinical/system language ("SYSTEM ALERT", "0 pending action items"), exclamation-heavy hype, jargon.
- **Emoji:** used *very* sparingly, only a soft botanical accent (🌿) in gentle empty/positive states. Never in dense UI or as bullets.

## VISUAL FOUNDATIONS
- **Palette:** earthy and warm. Ground is warm paper cream `#F5F3EE`; cards are pure white; the signature is forest/accent green (`#1B3A28` / `#2D5E3A`). Soft green tints (`#C8DBBC`, `#E6F0E0`) fill icon wells, active tabs and highlights. Semantic: red `#C2453A`, amber `#D4943A`, success = accent green. Max two background colors on a surface (cream + white); dark forest is reserved for feature/brand panels.
- **Type:** Playfair Display (serif) for anything expressive — brand, page titles, section headers, and large numerals — at weights 400/500/600. Inter for all body and UI. Geist Mono for time/quantity/meta. Serif headings sit tight (line-height ~1.15); body is airy (~1.55).
- **Spacing:** 4/8/16/24/32/48 scale. Generous padding — cards at 16–24px, web pages at 36/48. Calm, uncrowded density.
- **Corners:** 6 (checkboxes/chips), 12 (cards, inputs, buttons), 16 (large panels/stat cards), full (pills, avatars, tab bar, small icon buttons).
- **Borders:** hairline `#D4D0C8` 1px on cards and inputs; dividers are the same. Borders do the structural work — the look is line-led, not shadow-heavy.
- **Shadows:** soft and low-contrast. Cards mostly rely on border + white; a faint `0 2px 8–12px` shadow lifts floating elements (mobile tab bar, list cards). No hard/dark drop shadows.
- **Transparency & blur:** the mobile tab bar is translucent white (`rgba(255,255,255,.72)`) with `backdrop-filter: blur(16px)` — the one glass moment. Otherwise surfaces are opaque.
- **Backgrounds & imagery:** hand-illustrated, soft, warm nature scenes (sage greens, terracotta accents, cream). Used full-bleed in brand/feature panels and as card headers, never behind body text. No gradients, no photography, no grain/texture overlays.
- **Icons:** line icons, ~1.7–2px stroke, rounded caps/joins (see ICONOGRAPHY).
- **Buttons:** primary = accent-green fill, white text, radius-12, 15/20 padding, Inter 600. Secondary = white + hairline border. Icon+label pairs are common.
- **Hover:** links & primary buttons darken to `--accent-hover` (`#234B2E`); ghost/nav items go from muted to accent. **Press:** buttons scale to 0.98 (subtle squash), no color flash.
- **Animation:** quiet and quick — 120–150ms ease transitions on color/background/border; no bounces, no long or showy motion. Everything should feel settled, not springy.
- **Layout:** web is a 1440-max canvas with a 64px white top nav (bottom border) and 48px page padding; dashboards use a main column + ~340px side rail. Mobile is 393px with an iOS status bar and a floating pill tab bar pinned to the bottom.
- **Cards:** white fill, 1px `#D4D0C8` border, 12–16px radius, optional soft shadow; event/schedule rows add a 3px full-radius colored left bar to signal category/urgency.

## ICONOGRAPHY
- The source uses **line icons** from two libraries: **Lucide** on the mobile app (`house`, `calendar`, `list-todo`, `users`, `settings`, `leaf`, `signal`, `wifi`, `battery-full`, `check`, `plus`, `shopping-cart`, `store`, `chevron-right`) and **Phosphor** on web (`tree-evergreen`/`leaf`, `house`, `caret-down`, `user-plus`, `plus`, `list-checks`, `shopping-cart`, `calendar-blank`). Both are line/regular weight, ~1.7–2px stroke, rounded joins — visually consistent.
- Icons appear in **round accent-subtle wells** (mobile quick actions, 36px) or **rounded-square tinted tiles** (web stat cards, 44px, radius-md), tinted to match state (green wells, amber/red for warning/error).
- **This design system** ships an inline substitute set in `ui_kits/_shared/icons.jsx` (Lucide/Phosphor-style) so kits and cards have no external icon dependency. **Substitution flagged:** these are hand-matched redraws, not the exact Lucide/Phosphor packages. For production, install `lucide-react` (mobile) and `@phosphor-icons/react` (web) and use the glyph names above. No icon-font or SVG sprite was present in the sources to copy.
- **Emoji / unicode:** emoji used only as an occasional soft accent (🌿); no unicode glyphs used as functional icons.

## Logo
No logo file was supplied. The source renders the brand as a **leaf/tree mark inside an accent-green circle** next to the **"onsplan" wordmark in Playfair Display** (see `foundations/brand-logo.html`). That icon-in-circle treatment is the source's own mark, reproduced with a Lucide-style leaf — it is **not** a reconstructed proprietary logo. **Ask the user for an official logo asset** if one exists.

## Index — root manifest
- `styles.css` — global entry (import this). Imports the four token files + base resets.
- `tokens/` — `fonts.css`, `colors.css`, `typography.css`, `spacing.css`, `base.css`.
- `components/core/` — Button, IconButton, Input, Card, Checkbox, Avatar, Badge (+ `core.card.html`).
- `components/navigation/` — TopNav, TabBar (+ `navigation.card.html`).
- `foundations/` — specimen cards: colors, type, spacing, radius/shadow, brand (logo, illustrations, avatars, voice & tone).
- `ui_kits/web-app/` — interactive web recreation; `ui_kits/_shared/icons.jsx`.
- `assets/img/` — brand illustrations (scene-1/2/3).
- `thumbnail.html` — homepage tile. `SKILL.md` — Agent-Skills manifest.

## CAVEATS
- **Mobile-app experience** is now built as `templates/mobile-app/MobileApp.dc.html` — a full clickable prototype covering all 12 screens. Its screen chrome (grocery rows, chips, quantity stepper, list section headers, iOS status bar, voice/waveform, share sheet, context menu, widget cards) is authored inline in the template following DS tokens; promote any of these to formal `components/` primitives if they start recurring across templates.
- **Fonts** are Google-Fonts-linked, not self-hosted (compiler shows 0 fonts). Provide `.woff2` files to self-host.
- **Icons** are hand-matched redraws of Lucide/Phosphor, not the original packages.
- **No logo asset** — using the source's leaf-in-circle + wordmark treatment.
