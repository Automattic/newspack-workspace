# Agent guidelines for newspack-theme

This file covers what is specific to `newspack-theme`. Shared conventions (Docker commands, `n` script, coding standards, git rules, etc.) are in the root `newspack-workspace/AGENTS.md`.

## Overview

`newspack-theme` is a classic WordPress theme (not a block theme) providing the design foundation for Newspack sites. The repository contains the base theme and five child themes that share its codebase:

- `newspack-theme` — Base theme. All child themes extend this.
- `newspack-joseph`, `newspack-katharine`, `newspack-nelson`, `newspack-sacha`, `newspack-scott` — Child themes. Cosmetic variants only (colors, typography accents). Identical in features.

**Repository layout quirk**: The base theme lives at `newspack-theme/newspack-theme/`, not at the repo root. The repo root holds the shared build tooling (webpack, SCSS compiler, package.json) that compiles assets for all six themes at once.

## Common Gotchas

- **`npm run lint` skips PHP.** Run `npm run lint:php` separately for PHP linting.
- **No Composer autoloading.** There are only two PHP classes (`Newspack_SVG_Icons`, `Newspack_Walker_Comment`), both manually required in `functions.php`. No namespace is used. Do not introduce PSR-4 autoloading or namespaces.
- **SCSS compilation uses a custom Node script**, not standard webpack/PostCSS. `scripts/compile-scss.js` handles all themes, RTL generation, and cssnano optimization. Don't try to run it through webpack.
- **Webpack entry points are auto-discovered.** Any `newspack-theme/js/src/*.js` file or `newspack-theme/js/src/*/index.js` file becomes an entry point automatically. No changes to `webpack.config.js` are needed when adding JS files that follow this convention.
- **Do not add feature code to child themes.** All feature development belongs in the base `newspack-theme`. Child themes are cosmetic variants only.
- **This is a classic theme, not a block theme.** It has no `theme.json`. Template changes happen in PHP files, not the Site Editor. Colors, typography, and layout are managed through the Customizer, not `theme.json`.
- **The theme exposes hooks consumed by Newspack plugins and third-party integrations.** Before removing or renaming any `apply_filters` or `do_action` call, check whether it has external consumers — it may be a breaking change.
- **AMP code is legacy.** The codebase contains AMP-aware branches throughout (`newspack_is_amp()`, `amp-fallback.js`, etc.). Do not remove this code, but do not add AMP support to new features. New development does not need to consider the AMP path.
- **Child theme SCSS uses relative `@use` paths back to the base theme** (e.g., `@use "../../newspack-theme/sass/style-base"`). These paths break if directory structure changes.
- **`single-feature.php` and `single-wide.php` are near-identical templates.** Changes to one almost always need to be applied to the other.
