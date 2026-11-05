# Newspack Block Theme: Agent Instructions

This file covers what is specific to `newspack-block-theme`. Shared conventions (Docker commands, `n` script, coding standards, git rules, etc.) are in the root `newspack-workspace/AGENTS.md`.

## Overview

`newspack-block-theme` is a WordPress block theme. Templates, template parts, and patterns are HTML files in `templates/`, `parts/`, and `patterns/` respectively. Global styles, layout, typography, and color are controlled via `theme.json`. Eleven style variations exist in `styles/`, `01-theme-alt.json` to `11-nocturne-alt.json`.

## Global styles conventions

Write CSS variable references in `theme.json` and `styles/*.json` as `var(--x)`, with no spaces inside the parentheses. When a value wraps `var()` in another function, leave a space before that function's closing parenthesis, for example `color-mix( in oklab, var(--wp--preset--color--accent) 85%, var(--wp--preset--color--base) )`.

WordPress core resolves global styles with `WP_Theme_JSON::convert_variables_to_value()`, which `wp_get_global_styles()` uses for the `resolve-variables` transform. Its pattern only matches the no-space form, and it otherwise swallows the closing parenthesis of a wrapping function. The `newspack-ui` "Theme" style reads these resolved values to take the site's colors, fonts and radii, so a spaced `var( --x )` would silently break that. `tests/test-global-styles.php` fails if the spaced form comes back.

## Blocks

Do not add new blocks to this repo. New blocks should be added to `newspack-plugin` (most cases) or `newspack-blocks`. The existing `includes/blocks/subtitle-block/` is the only block that belongs here as it is tightly coupled to theme-level post metadata.

## Gotchas

- **`npm run lint` skips PHP.** Lint PHP from the workspace root (see the root guide).
- **`style.css` and `style-rtl.css` are generated.** They are copied from `dist/` after the webpack build. Edit the SCSS source in `src/scss/`, not the root CSS files.
- **Nested patterns are not auto-registered by WordPress.** WordPress only auto-registers pattern files at the top level of `/patterns/`. Subdirectory patterns are handled by the recursive `register_nested_patterns()` in `includes/class-patterns.php`, which traverses all depths automatically — no manual registration is needed when adding a new file to a subdirectory. Note that `patterns/plugins/jetpack/` is a two-level-deep subdirectory containing Jetpack sharing button patterns; plugin-specific patterns live there.
- **Webpack entry points for blocks are auto-discovered.** Any `.js`, `.jsx`, `.ts`, or `.tsx` file in `includes/blocks/*/` becomes an entry point automatically. No changes to `webpack.config.js` are needed.
- **External patterns are intentionally blocked.** The theme prevents Jetpack, WooCommerce, and Automattic remote patterns from loading. This is by design — do not remove these filters.
- **After adding a new PHP class file, run `composer dump-autoload`.** The `includes/` directory uses classmap autoloading, not PSR-4.
- **The Article Subtitle block has two completely different implementations.** In the post editor it is not a block at all — it injects a `contenteditable` div directly into the DOM and updates post meta via `editPost()`. In the site editor it registers normally via `registerBlockType()`. Modifying it requires understanding which context you are in.
- **Style variations require a unique `className` in their JSON.** The theme reads `wp_get_global_settings()['custom']['className']` and adds it as a `theme-variation-{className}` body class used for CSS scoping. A new style variation in `styles/` must define this value or scoped styles will not apply.
