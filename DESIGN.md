# ViStud design guide

The shared UI and UX guide for everyone who builds a ViStud screen. It sets the brand, the theme and gradient rules, the visual scales, the shared components and their states, responsive behaviour, navigation and feedback, accessibility, and the checks a screen must pass at handoff.

- **Authority.** [ADR 0003](docs/adr/0003-web-workspaces-and-study-content.md) is the authority for every requirement it already states (themes and enforcement in §6, the shell and preserved state in §4, saving and conflicts in §5.3, offline in §7, the workspaces in §10, responsive layout in §11). This guide adds the visual detail and links to the ADR rather than repeating it. Where the two disagree, the ADR wins and this guide is corrected. The gradient rules below were added to ADR 0003 §6 at the same time, so the two agree.
- **Scope.** The rules apply to every screen from M1 on. WP6 uses the foundations here for its functional screens, which replaces the earlier "unstyled markup" direction. The full M2 workspace, the theme editor, the calendar and the note editor are not built ahead of their milestones; where this guide describes them, it sets the rules they will follow.
- **Changes.** Changes to this guide go through the PM, like any shared contract ([contracts.md](docs/architecture/contracts.md#changing-a-contract)). A screen that needs something this guide does not cover asks for an addition instead of improvising.

## 1. Principles

1. **Calm by default, branded where it counts.** Gradients mark identity and orientation: the brand panel, headers and a few featured panels. Everything people read or type sits on calm, solid surfaces.
2. **Themes are data.** Components never contain a colour, a gradient stop or a gradient direction. A theme can restyle, or replace any gradient with a solid fill, without a component changing.
3. **Nothing is conveyed by colour alone.** Every state also has text, an icon, a shape or a position.
4. **Instant and honest.** Local actions happen at once, the page never reloads in normal use, and the save state always tells the truth (ADR 0003 §3 and §5.3).
5. **One product, two workspaces.** Students and admins share components and themes; navigation and the admin marker tell them apart.

## 2. Brand

### 2.1 The logo asset

The owner's logo is the colour reference. The original file is kept byte for byte at [resources/brand/vistud-logo-original.png](resources/brand/vistud-logo-original.png) (SHA-256 `e92d92e4…c3204db`).

**The checkerboard is part of the image, not transparency.** The original is a 1270 × 403 RGB PNG with no alpha channel, and the grey checkerboard behind the logo (12 px squares of `#222221` and `#131212`) is painted into its pixels. It must never appear in the interface. [resources/brand/clean-logo.php](resources/brand/clean-logo.php) removes it analytically: because the checkerboard is a known regular grid, each edge pixel's opacity is recovered as a mix of the logo colour and the known background. No colour is invented or retouched, and the faint light halo left around the V by an earlier cut-out is dropped. It writes four transparent assets to `public/brand/`:

| File | Use |
|---|---|
| `vistud-logo.png` | Full colour, on light surfaces and on the logo plate |
| `vistud-logo-white.png` | White knockout, on brand gradients only |
| `vistud-mark.png` | The V and leaves alone, full colour: favicon and small spaces |
| `vistud-mark-white.png` | The mark alone, white, on brand gradients |

The same script writes display-size copies, which is what pages load: `vistud-logo-96.png`, `vistud-logo-white-96.png`, `vistud-mark-96.png` and `vistud-mark-white-96.png` (96 px tall, enough for a 32 px logo on a 3× screen), plus `favicon-32.png` and `apple-touch-icon-180.png`. The full-size files stay the source, and the large white mark is still used for the watermark. `<x-logo>` picks the small copy unless given `large`.

### 2.2 Brand colours

Measured from the interior pixels of the original, never guessed. They are recorded as data in [resources/brand/logo-colours.json](resources/brand/logo-colours.json).

| Name | Hex | Where in the logo | Contrast with white | OKLCH (L, C, h) |
|---|---|---|---|---|
| Deep blue | `#003272` | The V and "Vi" | 12.36:1 | 0.331, 0.121, 258° |
| Lower leaf | `#003E7C` | The lowest leaf | 10.61:1 | 0.368, 0.120, 255° |
| Ocean | `#006E91` | The middle leaf; the wordmark at 60% | 5.77:1 | 0.503, 0.099, 229° |
| Teal | `#009B9E` | The top leaf; the end of the wordmark | 3.40:1 | 0.625, 0.106, 197° |

The wordmark is a left-to-right gradient: `#003272` at 0%, `#006E91` at 60%, `#009B9E` at 100%. This deep blue to ocean to teal direction is the brand's gradient, and the default themes follow it.

### 2.3 Logo treatments

> **PM decision (design review of PR #3): prefer the white logo directly on dark or gradient surfaces, and keep the full-colour logo on light surfaces.** This is **not implemented yet**. The table below describes the current code, which still puts the full-colour logo on a light plate on dark surfaces. Changing it is the first next task in [LOCAL-TAKEOVER.md](docs/handoff/LOCAL-TAKEOVER.md#4-what-is-left-in-wp6); update this section when it lands.

The artwork is never recoloured, stretched, outlined, rotated or given effects. Treatments differ only in what sits behind it.

| Surface | Treatment | Why |
|---|---|---|
| Light surfaces | Full colour, directly on the surface | Every logo colour has at least 3:1 against the light surfaces (teal is the weakest, 3.16:1 on the light page background) |
| Dark surfaces | Full colour on the **logo plate**: a rounded light panel (`--logo-plate`) behind the logo | Deep blue is only 1.4:1 on a dark surface, so the full-colour logo can't sit on it directly. The plate keeps the true colours (deep blue 11.2:1, teal 3.06:1 on the dark theme's plate) |
| Brand gradients | White knockout | The single-colour reverse version is the standard treatment on the brand's own colour |
| Under 24 px tall | The mark alone (favicon, collapsed sidebar) | The wordmark stops being legible |

- The `<x-logo>` component chooses the file; the theme decides the plate. `--logo-plate` is transparent in light themes and a light colour in dark ones, and the contrast check (§3.4) proves every logo colour stays at 3:1 or more against it.
- **Clear space:** at least the height of the "i" dot on every side. **Minimum size:** 24 px tall for the full logo, 16 px for the mark.
- Treatments on every built-in theme: [docs/design/previews/logo-treatments.png](docs/design/previews/logo-treatments.png).

### 2.4 Adapting the brand colours for the interface

The logo colours are right for artwork but not all of them carry text. The themes keep the brand direction and adjust lightness only where contrast requires it:

- **Teal can't sit behind white text** (3.40:1). Gradients that carry text end in a deeper teal: `#007378` in ViStud Light (5.64:1 with white), `#005F63` in ViStud Dark.
- **Ocean `#006E91` is the light theme's accent,** for fills with white text and for links (5.77:1 either way).
- **The dark theme** keeps deep brand gradients for identity, uses `#1A6D9E` for accent fills (white text 5.64:1, 3.07:1 against the surface), and a light teal (`#6CC4DC` for links, `#5CC3DD` for the focus ring, both above 8:1 on the dark surface).

The previously proposed indigo palette is replaced by this brand direction.

## 3. Colour, themes and gradients

Themes follow ADR 0003 §6. This section adds the gradient rules and the details of the implementation.

### 3.1 Semantic tokens

Components use semantic tokens only. The colour tokens are those of ADR 0003 §6.1, plus the gradient foregrounds and overlays in §3.3 and these additions: `--role-admin-on` (text on the admin marker), `--logo-plate` (§2.3), and `--qr-dark` and `--qr-light` (QR codes: dark modules on a light plate in every theme, dark mode included, because phone scanners expect that). The category, editor and chart groups join with the M2 screens that use them.

The full list is the allowlist in [app/Appearance/Theme.php](app/Appearance/Theme.php). In Tailwind, each colour token is a utility named after its role:

| Token | Utility name | Example |
|---|---|---|
| `--bg` | `canvas` | `bg-canvas` |
| `--surface`, `--surface-raised`, `--surface-sunken`, `--overlay` | same | `bg-surface-raised` |
| `--text`, `--text-muted`, `--text-subtle`, `--text-disabled` | `fg`, `fg-muted`, `fg-subtle`, `fg-disabled` | `text-fg-muted` |
| `--text-on-accent` | `on-accent` | `text-on-accent` |
| Borders, accent, interaction, status, focus, `--on-*`, `--role-admin*`, `--logo-plate` | same as the token | `border-border-strong`, `text-danger` |

Tailwind's own palette, shadows and radii are removed (`--color-*: initial` and friends in [resources/css/app.css](resources/css/app.css)), so an unthemed colour class simply doesn't exist.

### 3.2 Where gradients belong

Gradients are part of the visual identity. Use them for:

| Where | Token | Notes |
|---|---|---|
| Authentication branding panels | `--grad-brand` | The login screen's brand panel; a gradient band on mobile |
| Workspace and section headers | `--grad-header` | Headers only, never whole pages |
| Selected dashboard summary panels | `--grad-featured` | A few key panels per screen, not every card |
| Primary actions, where readability allows | `--grad-primary` and its hover and pressed states | One primary action per view |
| Selected segments and navigation items | `--grad-selected` | Subtle, a tint rather than the brand gradient |
| Large calm areas that benefit from depth | `--grad-surface` | Very low contrast, e.g. behind the login form |

**Keep calm, solid surfaces** under long-form notes, the editor, forms, tables, lists, dense information, dialogs and menus. A gradient never sits behind body text longer than a short paragraph, and gradients never animate (§4.7).

### 3.3 Gradient tokens and gradient surfaces

**Every gradient is stored centrally, complete.** A theme defines each gradient's angle and every stop's colour and position, or declares a solid fill instead:

```json
"brand":   { "angle": 135, "stops": [["#003272", 0], ["#006e91", 60], ["#007378", 100]] },
"primary": { "solid": "#a3294f" }
```

- Stops are opaque, 2 to 6 of them, positions 0–100 in ascending order; the angle is 0–360°. Nothing else is accepted.
- The server writes each gradient as `--grad-{name}`. A solid fill is written as a one-colour gradient, so `background-image: var(--grad-…)` works either way, and a theme can switch any gradient to solid without a component edit.
- Gradient tokens: `--grad-brand`, `--grad-header`, `--grad-featured`, `--grad-surface`, `--grad-primary`, `--grad-primary-hover`, `--grad-primary-active`, `--grad-selected`.
- Foregrounds, for text and icons on each: `--on-brand`, `--on-brand-muted`, `--on-header`, `--on-header-muted`, `--on-featured`, `--on-featured-muted`, `--on-primary`, `--on-selected`.
- Interaction overlays on gradients (translucent, composited over the gradient): `--brand-hover`, `--brand-pressed`, `--header-hover`, `--header-pressed`, `--header-selected`.
- The focus ring on a gradient surface is that surface's own foreground colour, which already passes 4.5:1 across it.

**Gradient surfaces.** A gradient area is marked with one utility, `surface-brand`, `surface-header`, `surface-featured` or `surface-subtle`. It sets the gradient and the foreground, and points `--text`, `--text-muted`, `--hover`, `--pressed`, `--selected` and `--focus-ring` at that gradient's own tokens. Shared components placed inside a gradient area therefore stay legible with no gradient-specific variants.

Components never write `linear-gradient(…)`, an angle, a stop or a Tailwind gradient utility (`bg-linear-*`, `from-*`, `via-*`, `to-*`).

### 3.4 Contrast

Every theme, built-in or custom, passes these checks before it is saved or shipped ([app/Appearance/ThemeValidator.php](app/Appearance/ThemeValidator.php)). ADR 0003 §6.4 sets the minimums; the gradient rules extend them:

| Pair | Minimum |
|---|---|
| Text and muted text on every surface, and on `--grad-surface` | 4.5:1 |
| Text on the hover, pressed, selected, selection and drag overlays over a surface | 4.5:1 |
| `--text-on-accent` on the accent and its hover and active shades; `--accent-contrast` on surfaces and `--accent-subtle` | 4.5:1 |
| Each status colour on surfaces and its `-subtle` background (it is used for error text); its `-on` colour on it; text on its `-subtle` background | 4.5:1 |
| **Each gradient foreground (`--on-*` and `--on-*-muted`) across its whole gradient**, including the primary gradient's hover and pressed states | 4.5:1 |
| **Gradient foregrounds over the interaction overlays on that gradient**, composited at every point | 4.5:1 |
| The focus ring on every surface, on `--grad-surface` and on `--focus-ring-offset`; the strong border, the accent and the admin marker on surfaces | 3:1 |
| Every logo colour on `--logo-plate` over each surface | 3:1 |
| `--qr-dark` on `--qr-light`, and `--qr-dark` must be the darker one | 7:1 |

**Across the whole background, not the endpoints.** Each gradient is sampled along its gradient line, 48 points between every pair of stops, interpolated in sRGB as browsers do for these colours. Every point of an element maps to a point on that line, so the samples cover the entire background. A midpoint can be darker than both ends, and the check catches that (tested in `ThemeValidatorTest`). Translucent foregrounds and overlays are composited over each sample before measuring.

**When a pair fails,** the theme is rejected with the pair, the worst ratio, where on the gradient it occurs, and a suggested fix: the nearest lightness of the foreground that passes every pair using it. The fixes available are: adjust the foreground, adjust the stops, or put the content on a theme-defined supporting surface (a solid panel from the surface tokens) instead of directly on the gradient.

Built-in themes are checked by `php artisan vistud:themes:build` and by the unit test that ADR 0003 §6.4 requires.

### 3.5 Enforcement

The three checks of ADR 0003 §6.3 cover gradients, interaction states and third-party components as well as flat colours. All three run in CI and must pass.

1. **Source scan** ([tests/Architecture/ThemeEnforcementTest.php](tests/Architecture/ThemeEnforcementTest.php)). In `resources/css`, `resources/js`, `resources/views` and the PHP that renders markup, it forbids hex colours, colour functions, named colours in colour properties and SVG paint attributes, colour strings in JavaScript, arbitrary Tailwind colours, **gradient functions** (`linear-`, `radial-` and `conic-gradient`, repeating or not), and **Tailwind gradient utilities**. The generated `resources/css/themes/themes.css` is the only exemption; theme data lives outside the scanned folders, in `resources/themes/*.json` and `resources/brand/`.
2. **Compiled CSS scan** (the same test). Every colour **and every gradient** in the built stylesheet sits inside a theme block. Fully transparent values and `color-mix()` over tokens and `currentColor` (Tailwind's placeholder default, which our own rule overrides) are the only exceptions. Tailwind reads only the views and scripts, never documents or tests, and its ring and shadow utilities are not used, because their defaults carry a colour; focus uses the shared outline and depth the elevation utilities.
3. **Sentinel theme** ([tests/Browser/theme-sentinel.spec.js](tests/Browser/theme-sentinel.spec.js)). Every token and **every gradient stop** gets its own improbable colour. On every screen, at desktop and mobile sizes, and in every state (idle, hover, pressed, focus, checked, disabled, loading, field errors, refused submission), every colour the browser computes for every element and its `::before`, `::after` and `::placeholder` must be a sentinel value: text, backgrounds, **gradient images**, borders, outlines, shadows, decorations, carets, scrollbars and SVG paint. Third-party components (calendar, charts, the editor, native dialogs) join this test as they arrive, in both workspaces.

The generated files are checked for freshness in CI: changing a theme without rebuilding fails the build.

### 3.6 Appearance: light, dark and system

- **Modes.** *System* (the default) follows the device's light or dark preference, live; *Light* and *Dark* pin one. Each mode maps to the account's light and dark themes (ViStud Light and ViStud Dark by default).
- **No flash of the wrong theme.** The server renders `<html data-theme data-theme-light data-theme-dark data-appearance>`. A tiny inline script in `<head>` ([resources/views/partials/theme-script.blade.php](resources/views/partials/theme-script.blade.php)) resolves the mode before the first paint. It contains no colours.
- **Switching is instant and in place.** Changing the mode only changes `data-theme` on `<html>`, which navigation never replaces. Every colour and gradient follows through the CSS variables, with no reload, no request and no component change, and a `vistud:theme-changed` event lets charts and other script-drawn components rebuild ([resources/js/appearance.js](resources/js/appearance.js)). The browser test proves this: after switching, the gradients have changed, the page was not reloaded, and the page's markup is byte-for-byte unchanged ([tests/Browser/theme-switch.spec.js](tests/Browser/theme-switch.spec.js)). A recording: [docs/design/previews/theme-switch.webm](docs/design/previews/theme-switch.webm).
- **Where the choice is kept.** Until accounts store the preference (M2, ADR 0003 §6.4), it is kept in the browser, and other tabs follow it.
- **Theme scopes.** Theme selectors are attribute selectors, so a `data-theme` on any element themes just that subtree. Theme previews and the logo sheet use this.

### 3.7 Built-in and custom themes

| Theme | Scheme | Purpose |
|---|---|---|
| **ViStud Light** (`vistud-light`) | Light | The default. The brand's deep blue to ocean to teal gradients |
| **ViStud Dark** (`vistud-dark`) | Dark | The default dark theme. Deep brand gradients, light teal accents, the logo plate |
| **Ember** (`ember`) | Light | An alternate preset proving the system's flexibility: a plum to berry to amber brand gradient at a different angle and stop positions, and a **solid** primary action. It uses exactly the same components. Kept as the third built-in theme (PM decision) |

Themes are JSON in `resources/themes/`. After editing one, run `php artisan vistud:themes:build`, which validates contrast and regenerates the stylesheet.

**Basic custom themes (M2).** The student picks seed colours (background, surface, text and accent, per ADR 0003 §6.4) and the server derives every token, gradients included, in OKLCH:

- `brand` and `header` keep the default direction: a deep start (the accent, darker and turned towards blue), the accent in the middle, and an end turned towards green, at 135° and 100°;
- `primary` runs from a slightly deeper accent to the accent, at 120°; its hover and pressed states step lightness down (light schemes) or up (dark schemes);
- `featured` and `selected` are soft tints of the accent over the surface; `surface` is the background with a barely visible fall-off;
- each foreground is white or the text seed, whichever passes across the whole gradient; if neither does, the stops' lightness is stepped until one does;
- any gradient can be set to solid.

The derived theme then passes the same validator. **Advanced editing** of every token, stop and angle comes later, under the same rules.

## 4. Foundations

### 4.1 Typography

Inter (variable, self-hosted from `@fontsource-variable/inter`), falling back to the system UI font. Weights 400, 500 and 600 only.

| Role | Size / line height | Weight | Tailwind | Use |
|---|---|---|---|---|
| Display | 36 / 40 | 600, tight tracking | `text-4xl` | Brand panels only |
| Title 1 | 30 / 36 | 600, tight | `text-3xl` | Wide-screen page titles |
| Title 2 | 24 / 32 | 600, tight | `text-2xl` | Screen headings (`h1`) |
| Title 3 | 20 / 28 | 600 | `text-xl` | Section headings |
| Lead | 18 / 28 | 400 | `text-lg` | Introductions |
| Body | 16 / 24 | 400 | `text-base` | Default text, and every input (16 px stops phones zooming on focus) |
| Small | 14 / 20 | 400–600 | `text-sm` | Labels (600), hints, secondary text, buttons in dense areas |
| Caption | 12 / 16 | 500 | `text-xs` | Badges and metadata only, never for anything essential |

Long-form reading (notes) uses the body size at a measure of 60–75 characters. Numbers in tables use tabular figures. Headings use `text-balance`.

### 4.2 Spacing

A 4 px base, using Tailwind's spacing scale: 4, 8, 12, 16, 20, 24, 32, 40, 48, 64. Page gutters: 16 px on mobile, 32 px on tablet, 48–56 px on desktop. Related items sit 4–8 px apart, fields in a form 20 px, sections 24–32 px.

### 4.3 Density and control sizes

| Density | Control height | Where |
|---|---|---|
| Comfortable (default) | 44 px (`btn-lg`, inputs) | Signed-out screens, forms, mobile everywhere |
| Standard | 40 px | Toolbars and workspace actions on desktop |
| Compact | 32 px | Admin tables and dense lists on desktop only |

On touch devices every control is at least 44 px whatever the density (§6.2).

### 4.4 Borders and radii

- **Borders:** 1 px `--border` for containers; 1 px `--border-strong` for inputs and other controls (3:1, so their edges are visible); `--divider` for separators; 1.5 px for checkboxes; 2 px for the focus ring.
- **Radii:** `sm` 6 px (checkboxes, badges), `md` 8 px (buttons, inputs), `lg` 12 px (alerts, segmented controls, menus), `xl` 16 px (cards, panels, dialogs), `2xl` 24 px (mobile bottom sheets), `full` (pills, avatars).

### 4.5 Shadows and elevation

One theme colour, `--shadow-color`, layered for depth: `elevation-sm` for buttons and the selected segment, `elevation-md` for cards, `elevation-lg` for dialogs, popovers and menus (utilities of the same names, or `var(--elevation-…)` in component CSS). Tailwind's own `shadow-*` utilities are not used, because their machinery carries colour defaults of its own. Dark themes rely more on `--surface-raised` and borders, with a stronger, darker shadow colour. Shadows never carry meaning.

### 4.6 Icons

Lucide 1.48 (ISC), inlined by `<x-icon>` and drawn with `currentColor` at a 2 px stroke. Sizes: 16 px beside small text, 20 px by default, 24 px in navigation. Decorative icons are hidden from screen readers; an icon-only button has an `aria-label` and a tooltip. Icons are copied from `lucide-static` into `resources/icons/` with `npm run icons`, so the server never needs `node_modules`.

### 4.7 Motion

Transitions last 120–200 ms, ease-out, and only on colour, opacity, shadow and transform. Gradients are **static**: they never animate, shift or pan. With reduced motion requested, every change is instant, spinners stop, and loading is shown by its label alone.

## 5. Components and their states

Shared components live in `resources/views/components/` and their state styles in [resources/css/components.css](resources/css/components.css). Every state uses its token. Hover and pressed are translucent overlays (`--hover`, `--pressed`), so they work on any surface, gradients included.

| Component | Hover | Focus | Selected / checked | Disabled | Loading | Error |
|---|---|---|---|---|---|---|
| **Primary button** | `--grad-primary-hover` | Shared ring | — | Solid `--surface-sunken`, `--text-disabled`, `not-allowed` cursor | Spinner and a busy label ("Logging in…"), repeated submits ignored | — |
| **Secondary, ghost, danger buttons** | `--hover` overlay | Shared ring | — | As above | As above | — |
| **Text input** | Border to `--text-subtle` | Shared ring, border to `--focus-ring` | — | `--surface-sunken`, `--text-disabled` | — | `--danger` border, `aria-invalid`, message below with an icon, linked by `aria-describedby` |
| **Checkbox** | Border to `--text-subtle` | Shared ring | `--accent` fill with a tick | `--surface-sunken` | — | As for inputs |
| **Segmented control** | `--hover` overlay | Ring around the option | `--grad-selected` with `--on-selected` and a shadow | Dimmed text, no hover | — | — |
| **Link** | Thicker underline | Shared ring | — | — | — | — |
| **Alert** | — | Shared ring when focused programmatically | — | — | — | Danger tone: icon, title and message, announced at once |

- **Focus** is always the shared style: a 2 px `--focus-ring` outline, 2 px out, with the gap filled by `--focus-ring-offset`, so it is visible on any background. Outlines are never removed without it (ADR 0003 §6.3).
- **Disabled** controls also say why when it isn't obvious (a hint or tooltip). Prefer leaving an action enabled and explaining on use.
- **Loading** stays on the element that is loading; nothing blocks the whole screen (ADR 0003 §3).

### 5.1 Buttons

`<x-button variant="primary|secondary|ghost|danger" size="md|lg" busy-label="…">`. One primary button per view. Labels are verbs ("Log in", "Save changes"). A destructive action is `danger` and asks for confirmation in a dialog that names what will be lost.

### 5.2 Fields, checkboxes and choices

`<x-field>` renders a visible label (never a placeholder instead of a label), an optional hint, and the error from the validation bag. Password fields can offer a reveal button (`revealable`). Inputs set `autocomplete`, `inputmode` and `enterkeyhint` for the mobile keyboard (§6.4). `<x-checkbox>` makes the whole row the target.

**Errors** name the field and say how to fix it; they never echo stored content ([conventions.md](docs/architecture/conventions.md#validation)). A failure that belongs to the whole form, such as a refused login, is one alert for the form, not a mark on one field.

### 5.3 Alerts and inline messages

`<x-alert tone="danger|warning|success|info" title="…">`: an icon, a title and a message on the tone's subtle background. Danger and warning use `role="alert"`; success and info use `role="status"`.

### 5.4 Cards, panels and headers

Cards are `--surface-raised` with a 1 px `--border`, `xl` radius and `elevation-md`. On mobile a form card drops its frame and uses the full width. Featured summary panels use `surface-featured`; section headers use `surface-header`.

### 5.5 Coming with M2

Menus, popovers, native `<dialog>` with an `--overlay` backdrop, mobile bottom sheets, toasts, tabs, tables (cards on mobile), skeletons, the thin navigation progress bar, the save indicator (§8.3), the workspace switch and the admin marker. Each follows the state table above and joins the sentinel test when it lands.

### 5.6 Third-party components

Third-party UI takes its colours from tokens and never from its own palette: FullCalendar's skeleton CSS with our token mapping, ECharts themed from computed tokens and rebuilt on `vistud:theme-changed`, Tiptap with our own CSS, native controls through `accent-color` and custom styling (ADR 0003 §6.2). Gradients in third-party components come only from the `--grad-*` tokens. Each is covered by the sentinel test in all its interactive states.

## 6. Layout and responsive behaviour

### 6.1 Breakpoints and layouts

ADR 0003 §11 defines the workspace layouts: desktop from 1280 px (resizable sidebar, main area, optional right panel), tablet 768–1279 px (drawer and sliding panels), and a deliberately different mobile layout below 768 px (bottom navigation, drill-down lists, a full-screen editor, bottom sheets). Admin uses the same breakpoints with its own sidebar; its tables become cards on mobile.

Signed-out screens use a simpler split: the brand panel beside the form from 1024 px, and a gradient band above the form below that (§7.1).

### 6.2 Touch targets

At least 44 × 44 px for every interactive element on touch devices (`pointer: coarse`), with at least 8 px between neighbouring targets. The browser test checks this on the login screen.

### 6.3 Long content

- Text wraps; nothing depends on a name or title fitting. Long titles wrap to two lines, then truncate with an ellipsis and the full text in a tooltip and the accessible name.
- Long unbroken strings (URLs, email addresses) break (`overflow-wrap: anywhere`) instead of widening the page. **No horizontal page scroll at any size**; only code blocks and wide tables scroll inside their own container.
- Layouts are tested with long names, long emails and 200% text zoom.

### 6.4 The mobile keyboard

- Inputs are 16 px so phones don't zoom on focus, and use the right `type`, `inputmode`, `autocomplete`, `autocapitalize` and `enterkeyhint`.
- Pages size to the dynamic viewport (`min-h-dvh`), so the keyboard never hides the field being edited or the submit button; focused fields scroll into view.
- The editor's toolbar sits above the keyboard (ADR 0003 §11).

## 7. Screens

### 7.1 Signed-out screens

Login, two-factor challenge, password reset and invitation acceptance share `<x-layouts.auth>`: a `surface-brand` panel with the white logo and a short line of copy, and the form on a calm card over `surface-subtle`. The brand line "Your study brain, kept for you." stays for now (PM decision). The appearance switcher sits below the form. These screens submit as normal forms because the session changes; that is the one place a full page load is expected.

Previews of the login screen: [docs/design/previews/](docs/design/previews/).

### 7.2 Student and admin workspaces

- **Shared:** the same shell structure, components, scales, tokens and the account's theme in both workspaces (ADR 0003 §10).
- **Distinct navigation:** each workspace has its own sidebar (and, on mobile, its own bottom navigation). Admin navigation: Overview, Accounts, Roles, Theme presets, Background jobs, Audit log, Platform settings.
- **A clear admin marker:** a permanent "Admin" pill in `--role-admin` with `--role-admin-on` text and a shield icon, at the top of the admin sidebar and in the mobile header, whenever the admin workspace is active. The word and the icon carry the meaning, not the colour. The workspace switch is shown only to admins.

## 8. Navigation, state and feedback

### 8.1 No reloads, history and deep links

Normal use never reloads the page: `wire:navigate` swaps the main area while the shell stays (ADR 0003 §4). Every screen and note has a real URL, back and forward restore the previous screen and its scroll position, and a direct link opens the full shell at that screen. An unknown or inaccessible ID shows the same "not found" state inside the shell.

### 8.2 Preserved state

What survives navigation, reloads and restarts is ADR 0003 §4's table. Visually: moving around never resets open editors, expanded tree nodes, panel sizes or the theme.

### 8.3 Saving, offline, conflicts and failures

The save indicator uses the labels of ADR 0003 §5.3 (*Saved*, *Saved on this device*, *Saving…*, *Not saved, retrying in N s*, *Conflict, needs your choice*, *Not stored on this device, keep this tab open*). Each state has its own icon and text as well as its colour, and changes are announced politely to screen readers. It sits in the main area's header, in the same place on every screen.

- **Offline:** a banner states that changes are kept on this device and will sync when back online (ADR 0003 §7). It never covers the content.
- **Conflict:** the conflict screen offers the four choices of ADR 0003 §5.3, with both versions visible.
- **Failures** say what happened and what will happen next, offer a retry where it helps, and keep the user's input.
- **Loading:** a thin progress bar for navigation, a skeleton in the loading area after 150 ms, and never a full-screen spinner (ADR 0003 §3).

### 8.4 Protecting unsaved work

Drafts are stored on the device before every save (ADR 0003 §5.3). Leaving a form with unsubmitted input asks for confirmation, and the browser warns before closing a tab whose changes exist only in memory. Logging out with unsynced drafts offers *Sync now*, *Log out and keep drafts on this device* or *Log out and discard*.

## 9. Accessibility

WCAG 2.2 AA is the floor.

- **Keyboard:** everything works with the keyboard, in a logical order. A radio group is one tab stop, with arrow keys inside it. Menus and dialogs trap and restore focus. Shortcuts never replace a visible control.
- **Visible focus:** the shared focus ring on everything focusable, on every surface and gradient (§5).
- **Labels:** every control has a visible label or, for icon-only buttons, an accessible name. Errors are linked to their fields. Headings are in order, with one `h1` per screen.
- **Colour:** contrast per §3.4; states and messages never rely on colour alone.
- **Reduced motion:** honoured everywhere (§4.7). **Gradients are static by default** and never animate.
- **Forced colours:** in Windows contrast themes, gradients step aside for system colours and controls keep visible edges.
- **Zoom:** usable at 200% text size and at 320 px wide without horizontal scrolling.
- **Checked automatically:** axe runs on every screen in every built-in theme at desktop and mobile sizes, together with keyboard-order and touch-target checks ([tests/Browser/login-accessibility.spec.js](tests/Browser/login-accessibility.spec.js)).

## 10. Checks at UI handoff

A pull request that adds or changes a screen includes:

1. **Screenshots** at 390 × 844, 834 × 1194 and 1440 × 900, in ViStud Light and ViStud Dark (and Ember for screens with gradients), plus the screen's states: empty, loading, error, focus and long content. `PREVIEWS=1 npx playwright test previews` writes them to `docs/design/previews/`.
2. **Interaction checks,** automated where possible: the keyboard walk-through; the theme switching without a reload; the sentinel test in every state; axe in every built-in theme; touch targets; reduced motion; no horizontal scrolling; long content.
3. **PM visual review** before the screen is merged, and before styling further screens of the same package.

## 11. Implementation map

| What | Where |
|---|---|
| Theme data | `resources/themes/*.json` |
| Brand assets and measured colours | `resources/brand/`, `public/brand/` |
| Theme model, validator, stylesheet writer | `app/Appearance/` |
| Generated theme stylesheet | `resources/css/themes/themes.css` (`php artisan vistud:themes:build`) |
| Token utilities, gradient surfaces, base styles, component states | `resources/css/app.css`, `base.css`, `components.css` |
| Appearance switching, form behaviour | `resources/js/appearance.js`, `forms.js` |
| Shared Blade components | `resources/views/components/` |
| Enforcement | `tests/Architecture/ThemeEnforcementTest.php`, `tests/Browser/theme-sentinel.spec.js` |
| Setup, build and browser tests | [docs/development/setup.md](docs/development/setup.md) |
