# newspack-ui-shadcn

A shadcn theme and registry built from the newspack-ui tokens, so sites outside WordPress can use newspack-ui's Neutral style. shadcn and Radix keep the component behavior; this package supplies the tokens and how each component looks.

The package is private and never published to npm. Sites install its items from the registry with `npx shadcn add <url>`. The registry, its docs and a component showcase are served from https://newspack-ui-theme.space.fast/, which is built from this package's `dist/` and published separately; nothing in this repo deploys it.

## Build

```sh
pnpm --filter newspack-ui-shadcn build
```

The tests need Node 22.18 or later: they import a TypeScript file through Node's default type stripping.

The build runs four steps in order:

1. `scripts/tokens.mjs` compiles newspack-ui from `plugins/newspack-plugin/src/newspack-ui`, with `packages/motion` and `packages/colors`, into `build/newspack-ui/`: the CSS, `tokens.json`, `elevation.json` and `colors.json`.
2. `dark-palette/generate.mjs` derives the dark values from those tokens with APCA and writes them to `build/dark-palette/`, with `report.md` listing every pair it checked and `preview.html` showing the result.
3. `scripts/build.mjs` writes the shadcn theme, the registry items and the site kit to `dist/`.
4. `scripts/build-brand.mjs` derives the brand theme from the Neutral output, writes `dist/brand.css`, the `newspack-brand` and `newspack-brand-fonts` items and the brand section of `mapping.json`, then scores every brand text and fill pair with APCA into `build/brand/report.md`. The step prints how many pairs pass, and the tests fail if any does not.

newspack-ui is read from this checkout, so a change to its tokens reaches the theme on the next build. The build is left out of the root `build:packages`, so it never runs on `pnpm install`: a renamed newspack-ui token fails this build, not the install. CI builds the package only when its own files change, so build it after changing newspack-ui's tokens or `packages/colors`.

`REGISTRY_BASE` sets the URL the registry items point at. It defaults to the product site.

## Exports

| Path | Contents |
| --- | --- |
| `newspack-ui-shadcn/theme.css` | The shadcn variables mapped to newspack-ui tokens, light and dark |
| `newspack-ui-shadcn/brand.css` | The Newspack brand theme: Neutral's mapping with the brand overrides, and the blue section values on `.dark` and `.newspack-brand-inverse` |
| `newspack-ui-shadcn/dark.css` | newspack-ui's own dark values |
| `newspack-ui-shadcn/mapping.json` | Every shadcn variable with its token, plus the gaps and values with no token |
| `newspack-ui-shadcn/registry/*` | The registry items |
| `newspack-ui-shadcn/site-kit/*` | The shared site chrome (header, footer, drawer, docs layout) and the brand logo and deck items |

The brand items: `newspack-brand` and `newspack-brand-fonts` are in `registry/*`; the rest are in `site-kit/*`:

| Item | Contents |
| --- | --- |
| `newspack-brand` | The brand theme, an alternative to `newspack-ui-neutral` |
| `newspack-brand-fonts` | `styles/newspack-brand-fonts.css`, the American Grotesk `@font-face` rules |
| `newspack-logo` | The Newspack logo as an inline SVG, in four variants |
| `newspack-favicon` | `public/favicon.svg`, the Newspack symbol |
| `deck-section` | `Deck` and `DeckSlide`: full-viewport slides, scroll snapping, keyboard navigation, slide changes on the newspack-motion travel rule |
| `deck-nav` | The dot navigation |
| `deck-layouts` | Starter slides: opening, closing, statement, image with text, stats, quote, logos, and a blank `ContentSlide` |
| `newspack-deck` | The theme, the logo, the favicon, the deck and its dots and layouts in one install |

Every item whose files import `@/lib/utils` declares shadcn's own `utils` item, so the CLI installs `cn` in a project that never ran `shadcn init`.

`node scripts/install.mjs <dir>` copies the registry's component and lib files into a project without going through the registry.

## Brand theme

A second theme for internal Newspack work such as sales decks. The page is always light; a section can be blue. A site installs it instead of Neutral. It maps the same shadcn variables, so every kit component works in it unchanged.

Cobalt (`#003DA5`) is used for the default Button fill, links, the focus ring, checked Checkbox and Radio, the active Tab marker, and h1 and `font-display` text. Everything else keeps Neutral's values, and `variant="brand"` renders like the default Button. Emerald, Morganite and Amber are accent tokens for backgrounds, shapes and charts, never for actions. The full accents carry short or large text only (their ink is Lc 62.8 to 63.2); body copy goes on the `-subtle` tints, which the gate checks at Lc 75. Anchors with no class are Cobalt and underlined.

Add the `newspack-brand-inverse` class, or `dark`, to a section or slide to make it blue: a Cobalt surface with white text, white buttons with Cobalt ink, a white focus ring and white checked marks. The two classes are equivalent and set the same values; `dark` also turns on `dark:` utilities inside the section. Every kit component and state works in a blue section: status Alerts and Badges use dark status fills with white text and light icons, invalid fields use a light red edge and message, the accent `-subtle` tints become accent-tinted Cobalt so white text reads on them, and the chart series swap Cobalt for light blues. Put the class on a section or slide, not on an element that carries its own `bg-*` utility: the utility keeps its fill while the tokens flip to white text. Popups that render through a portal (the Select list, Dialog, Sheet and toasts) mount outside the section, so one opened from a blue section renders on the light surface.

Blue is a per-section choice, not a page theme. A site built with the site kit sets `DARK_MODE = false` in `site.ts`, so it has no theme toggle and no theme head script and never follows the system color scheme. A deck has no `site.ts` and no theme script, so it needs nothing. The brand item gives `body` the surface, the text color and Inter, so neither needs a `body` rule of its own.

h1 is American Grotesk Compressed Black, uppercase and Cobalt, and `font-display` gives any element the same style. h2 is Compressed Black in sentence case and the text color. h3 to h5 are American Grotesk Medium in the text color. `.newspack-brand-card-title` gives a heading inside a Card the h5 size, so it reads as a card title below the section's h2. In a blue section every heading is white.

American Grotesk is licensed for newspack.com and anything under space.fast only. The files are never committed here. They are served from https://newspack-ui-theme.space.fast/fonts/american-grotesk/: `american-grotesk-compressed-black.woff2`, `american-grotesk-regular.woff2`, `american-grotesk-italic.woff2`, `american-grotesk-medium.woff2` and `american-grotesk-medium-italic.woff2`. From the site's root, this downloads all five into `public/fonts/american-grotesk/`:

```sh
curl -fsSL --create-dirs -o 'public/fonts/american-grotesk/#1.woff2' 'https://newspack-ui-theme.space.fast/fonts/american-grotesk/{american-grotesk-compressed-black,american-grotesk-regular,american-grotesk-italic,american-grotesk-medium,american-grotesk-medium-italic}.woff2'
```

Then import `styles/newspack-brand-fonts.css` once, before the theme. Without the files the theme falls back to Inter.

For a deck, import `styles/newspack-deck.css`, which `deck-section` ships, and link the favicon from the head: `<link rel="icon" type="image/svg+xml" href="/favicon.svg" />`.

The content layouts take `tone="brand"` for a blue slide and a `className`, merged last. A custom slide matches them with `ContentSlide`, or with `SLIDE` (or only `SLIDE_PADDING`) on a `DeckSlide`. `ImageTextSlide` takes `layout="image-led"` for product screenshots: the image gets five eighths of the width from 1024px and is never cropped. `StatsSlide` fits two to four stats: one row from 1024px, two columns below it. `OpeningSlide` takes a `heading` to render in place of `title`, for example to keep a publication's name on one line with `<span className="inline-block">`.

A deck moves between slides with a scripted scroll that follows the newspack-motion travel rule: `--newspack-ui-duration-modal` up to 240px, then 1ms more for every 4px, up to 450ms, on `--newspack-ui-ease-drawer`. Under reduced motion it jumps. A wheel, a touch, a press on the deck's scrollbar or a scrolling key the deck does not handle stops it. A `scroll-behavior` set on the deck has no effect on slide changes. The dot fill fades on `--newspack-ui-duration-color` and `--newspack-ui-ease-color`. Each dot is a 32px button with a pointer cursor; on hover (on devices that can hover) and on keyboard focus the dot scales to 1.5 over `--newspack-ui-duration-press` on `--newspack-ui-ease-out`. Under reduced motion it does not scale: hover shows the neutral fill behind the dot instead, and focus shows the ring. Each dot takes the tone of the slide behind it, so the column changes color as a slide boundary passes it. With a mouse the nav shows on load and hides after 1s, then shows while the pointer is within 80px of the right edge or keyboard focus is inside it; a tap shows it for a moment, and without a fine hovering pointer it always shows. A mouse click on a dot moves focus to its slide. It fades and slides 4px on `--newspack-ui-duration-reveal` and `--newspack-ui-ease-out`, and only fades under reduced motion.

`brand.css` must be imported after `@import "tailwindcss"`, otherwise preflight's heading reset wins over the brand heading styles.

## Records

- `DECISIONS.md`: the decision log for the theme, the registry and the site kit.
- `gaps.md`: where the components differ from newspack-ui, and the values with no token.
- `dark-palette/DECISIONS.md`: how the dark values are derived.
