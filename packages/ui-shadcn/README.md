# newspack-ui-shadcn

A shadcn theme and registry built from the newspack-ui tokens, so sites outside WordPress can use newspack-ui's Neutral style. shadcn and Radix keep the component behavior; this package supplies the tokens and how each component looks.

The package is private and never published to npm. Sites install its items from the registry with `npx shadcn add <url>`. The registry, its docs and a component showcase are served from https://newspack-ui-theme.space.fast/, which is built from this package's `dist/` and published separately; nothing in this repo deploys it.

## Build

```sh
pnpm --filter newspack-ui-shadcn build
```

The build runs three steps in order:

1. `scripts/tokens.mjs` compiles newspack-ui from `plugins/newspack-plugin/src/newspack-ui`, with `packages/motion` and `packages/colors`, into `build/newspack-ui/`: the CSS, `tokens.json`, `elevation.json` and `colors.json`.
2. `dark-palette/generate.mjs` derives the dark values from those tokens with APCA and writes them to `build/dark-palette/`, with `report.md` listing every pair it checked and `preview.html` showing the result.
3. `scripts/build.mjs` writes the shadcn theme, the registry items and the site kit to `dist/`.

newspack-ui is read from this checkout, so a change to its tokens reaches the theme on the next build. The build is left out of the root `build:packages`, so it never runs on `pnpm install`: a renamed newspack-ui token fails this build, not the install. CI builds the package only when its own files change, so build it after changing newspack-ui's tokens or `packages/colors`.

`REGISTRY_BASE` sets the URL the registry items point at. It defaults to the product site.

## Exports

| Path | Contents |
| --- | --- |
| `newspack-ui-shadcn/theme.css` | The shadcn variables mapped to newspack-ui tokens, light and dark |
| `newspack-ui-shadcn/dark.css` | newspack-ui's own dark values |
| `newspack-ui-shadcn/mapping.json` | Every shadcn variable with its token, plus the gaps and values with no token |
| `newspack-ui-shadcn/registry/*` | The registry items |
| `newspack-ui-shadcn/site-kit/*` | The shared site chrome: header, footer, drawer, docs layout |

`node scripts/install.mjs <dir>` copies the registry's component and lib files into a project without going through the registry.

## Records

- `DECISIONS.md`: the decision log for the theme, the registry and the site kit.
- `gaps.md`: where the components differ from newspack-ui, and the values with no token.
- `dark-palette/DECISIONS.md`: how the dark values are derived.
