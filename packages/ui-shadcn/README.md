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
| `newspack-ui-shadcn/brand.css` | The Newspack brand theme: Neutral's mapping with the brand overrides, light only, and the `.newspack-brand-inverse` surface |
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
| `deck-section` | `Deck` and `DeckSlide`: full-viewport slides, scroll snapping, keyboard navigation |
| `deck-nav` | The dot navigation |
| `deck-layouts` | Starter slides: opening, closing, statement, image with text, stats, quote, logos |
| `newspack-deck` | The theme, the logo, the deck and its dots and layouts in one install |

`node scripts/install.mjs <dir>` copies the registry's component and lib files into a project without going through the registry.

## Brand theme

A second, light-only theme for internal Newspack work such as sales decks. A site installs it instead of Neutral. It maps the same shadcn variables, so every kit component works in it unchanged.

Cobalt (`#003DA5`) is used for the default Button fill, links, the focus ring, checked Checkbox and Radio, the active Tab marker, and h1 and `font-display` text. Everything else keeps Neutral's values, and `variant="brand"` renders like the default Button. Emerald, Morganite and Amber are accent tokens for backgrounds, shapes and charts, never for actions. The full accents carry short or large text only (their ink is Lc 62.8 to 63.2); body copy goes on the `-subtle` tints, which the gate checks at Lc 75. Anchors with no class are Cobalt and underlined.

Add the `newspack-brand-inverse` class to a section for a Cobalt surface with white text, a white focus ring and white buttons. Put it on a section or slide, not on an element that carries its own `bg-*` utility: the utility keeps its fill while the tokens flip to white text. Success, Warning, Error and Destructive Alerts and invalid-field styling are not supported on it.

American Grotesk is licensed for Automattic domains only. The files are never committed here. To use them, copy the five woff2 files into the site's `public/fonts/american-grotesk/` and import `styles/newspack-brand-fonts.css` once, before the theme. Without them the theme falls back to Inter. Get the files from the product site's `/fonts/american-grotesk/`.

Set `DARK_MODE = false` in `site.ts`. For a deck, import `styles/newspack-deck.css`, which `deck-section` ships.

`brand.css` must be imported after `@import "tailwindcss"`, otherwise preflight's heading reset wins over the brand heading styles.

## Records

- `DECISIONS.md`: the decision log for the theme, the registry and the site kit.
- `gaps.md`: where the components differ from newspack-ui, and the values with no token.
- `dark-palette/DECISIONS.md`: how the dark values are derived.
