# AI Agent Instructions for newspack-blocks

This file supplements the workspace-level instructions in [`../../AGENTS.md`](../../AGENTS.md). See that file for shared conventions (coding standards, commit format, build/test commands, git workflow, Docker environment). This document covers patterns and architecture specific to this repository.

## Overview

`newspack-blocks` is a WordPress plugin providing custom Gutenberg blocks for Newspack news sites. All blocks use **dynamic (server-side) rendering** via PHP `view.php` files. The editor UI is built with React/JSX and compiled into a single combined bundle.

## Block Architecture

### Dynamic Rendering

All blocks use `save: () => null` (or `<InnerBlocks.Content />` for nested mode) and render frontend HTML entirely via `view.php` files. The `Newspack_Blocks::manage_view_scripts()` method scans `src/blocks/*/` at plugin load, includes each `view.php`, and wires their `init` registrations.

### Combined Editor Bundle

Unlike many block plugins, ALL block editor scripts are compiled into a single `dist/editor.js` file. There are no per-block editor scripts. This bundle includes:
- All block `editor.js` files from `src/blocks/*/`
- Editor setup from `src/setup/editor.js` (Newspack category, block-styles, unregister-blocks)

### Separate View Bundles

Each block's frontend JS/CSS is compiled to separate files under `dist/<block-name>/`. View assets are conditionally enqueued only when the block is rendered via `Newspack_Blocks::enqueue_view_assets('<slug>')`.

### Block File Convention

Each block under `src/blocks/<block-name>/` typically contains:

| File | Purpose |
|---|---|
| `block.json` | Block metadata, attributes, supports. Not all blocks have one. |
| `index.js` | Exports `name`, `settings`, `title`. Does NOT call `registerBlockType()`. |
| `editor.js` | Entry point that calls `registerBlockType()`. Imported by combined editor bundle. |
| `edit.js` / `edit.tsx` | React component for the editor UI. |
| `editor.scss` | Editor-only styles. |
| `view.php` | **Server-side rendering** and block registration (primary render mechanism). |
| `view.js` / `view.ts` | Frontend JavaScript (compiled to `dist/<block-name>/view.js`). |
| `view.scss` | Frontend styles (compiled to `dist/<block-name>/view.css`). |
| `class-wp-rest-*.php` | REST API controller (for blocks with data-fetching needs). |

### Block Registration Flow

1. `newspack-blocks.php` defines constants, requires PHP files, and hooks REST controllers.
2. `Newspack_Blocks::manage_view_scripts()` iterates `src/blocks/*/`, includes each `view.php`.
3. Each `view.php` registers its block on `init` with a `render_callback`.
4. On `enqueue_block_editor_assets`, the combined `dist/editor.js` is enqueued with localized data via `newspack_blocks_data`.

## Block Inventory

| Block | Directory | SSR | View JS | Notes |
|---|---|---|---|---|
| Homepage Posts | `src/blocks/homepage-articles/` | Yes | Yes (load-more, infinite scroll) | Main content block. Redux store for deduplication. |
| Carousel | `src/blocks/carousel/` | Yes | Yes (Swiper slider) | Variant of homepage-articles. Shares its Redux store. |
| Author Profile | `src/blocks/author-profile/` | Yes | Style-only | Supports nested mode (InnerBlocks + block bindings). |
| Author List | `src/blocks/author-list/` | Yes | Style-only | Directory-style author listing. |
| Donate | `src/blocks/donate/` | Yes | Yes (two variants) | Frequency-based and tiers-based frontend scripts. |
| Checkout Button | `src/blocks/checkout-button/` | Yes | Style-only | WooCommerce integration. Uses `register_block_type_from_metadata`. |
| Iframe | `src/blocks/iframe/` | Yes | Yes (postMessage height) | File upload + embed. |
| Video Playlist | `src/blocks/video-playlist/` | Yes | Yes | **DEPRECATED.** No `block.json`. |

Production builds only include blocks listed in `block-list.json`. In development, all block directories are included.

## Gotchas

Things that are non-obvious and will trip you up:

- **REST controllers live in `src/blocks/`, not `includes/`**. Each block with data-fetching needs has its own `class-wp-rest-*.php` alongside its editor code, not in the central `includes/` directory.
- **`index.js` exports settings but does NOT register blocks.** Registration happens in `editor.js`, which imports from `index.js` and calls `registerBlockType()`. Editing `index.js` alone won't change registration behavior.
- **Video Playlist registers as `youtube-video-playlist`** in PHP but its directory is `video-playlist/`. The slug mismatch can cause confusion when searching for the block.
- **`view.scss` is imported in both `index.js` and `view.js`** for some blocks. The `index.js` import ensures editor preview styling; the `view.js` import handles the frontend. Removing either import breaks one context.
- **No Composer autoloading for plugin classes.** The plugin uses manual `require_once` in `newspack-blocks.php`. If you add a new PHP class, you must add a corresponding `require_once`.
- **All integrations with other plugins are defensive.** Always use `class_exists`/`function_exists` checks. The plugin must work without WooCommerce, Co-Authors Plus, Newspack Plugin, Jetpack, Yoast SEO, or The Events Calendar installed.

## Common Tasks

### Modify a block's editor UI

1. Find the block's `edit.js` (or `edit.tsx`) in `src/blocks/<block-name>/`.
2. Make changes to the React component.
3. Run `n build` or `n watch` to recompile the combined `dist/editor.js` bundle.
4. No PHP changes needed unless you're adding/changing block attributes (update `block.json` or the `register_block_type` call in `view.php`).

### Modify a block's frontend rendering

1. Edit the `view.php` in `src/blocks/<block-name>/`.
2. For frontend JS changes, edit `view.js`/`view.ts` and rebuild.
3. No editor rebuild needed for PHP-only changes, but the Docker container serves the source files directly, so changes are live on page refresh.

### Add a new block

1. Create a new directory under `src/blocks/<block-name>/`.
2. Follow the block file convention: at minimum, `block.json`, `index.js`, `editor.js`, `edit.js`, `view.php`.
3. Add the block slug to `block-list.json` for it to be included in production builds.
4. The webpack config auto-discovers `editor.js` and `view.js` files, so no webpack changes are needed.
5. Add a `require_once` in `newspack-blocks.php` if the block has PHP classes (e.g., REST controllers).

### Modify the modal checkout

The modal checkout is the largest subsystem. Key files:
- **PHP backend**: `includes/class-modal-checkout.php` (~79KB) with sub-classes in `includes/modal-checkout/`
- **Frontend JS**: `src/modal-checkout/modal.js` (dialog), `src/modal-checkout/index.js` (checkout logic)
- **Templates**: `src/modal-checkout/templates/` (checkout form, payment method, thank you page, coupon, gift subscription)
- **Analytics**: `src/modal-checkout/analytics/`

## Directory Structure

```
newspack-blocks/
├── newspack-blocks.php           # Main plugin file: constants, requires, hooks
├── block-list.json               # Controls which blocks are included in production builds
├── webpack.config.js             # Custom webpack config (extends newspack-scripts)
│
├── includes/                     # Core PHP classes (services, API, caching, modal checkout)
│   ├── class-newspack-blocks.php          # Main class: registration, asset management, query builder
│   ├── class-newspack-blocks-api.php      # REST API utilities
│   ├── class-newspack-blocks-caching.php  # Object-cache layer for dynamic blocks
│   ├── class-newspack-blocks-patterns.php # Block pattern registration
│   ├── class-modal-checkout.php           # WooCommerce modal checkout (largest file)
│   ├── modal-checkout/                    # Modal checkout sub-classes
│   └── tracking/                          # Data Events API integration
│
├── src/
│   ├── blocks/                   # Individual blocks (see Block Inventory above)
│   │   └── shared/               # Shared block utilities (author.js)
│   ├── block-patterns/           # PHP pattern files (homepage-posts, donations, subscribe)
│   ├── block-styles/             # Custom styles for core blocks (columns, group)
│   ├── components/               # Shared React editor components
│   ├── modal-checkout/           # Modal checkout JS/SCSS/templates
│   ├── setup/                    # Editor setup (category, block-styles, placeholder blocks)
│   ├── shared/
│   │   ├── js/                   # Shared JS utilities (utils.js, newspack-icon.js)
│   │   ├── sass/                 # Shared SCSS (_mixins.scss, _variables.scss)
│   │   └── authors.php           # Shared PHP author helpers
│   ├── templates/                # PHP templates (author-profile-card.php)
│   └── types/                    # TypeScript type declarations
│
├── dist/                         # Compiled output (gitignored)
├── tests/                        # PHPUnit tests
├── languages/                    # Translation files
└── bin/                          # Shell scripts (WP test install, i18n)
```

## Build System

### Webpack Entry Points

Defined in `webpack.config.js` using `newspack-scripts/config/getWebpackConfig`:

| Entry | Source | Purpose |
|---|---|---|
| `editor` | All `src/blocks/*/editor.js` + `src/setup/editor.js` | Combined editor bundle |
| `<block>/view` | Each `src/blocks/*/view.{js,ts}` | Per-block frontend scripts |
| `placeholder_blocks` | `src/setup/placeholder-blocks.js` | Placeholder UI for unavailable plugins |
| `block_styles` | `src/block-styles/view.js` | Core block style overrides |
| `modal` | `src/modal-checkout/modal.js` | Modal checkout dialog |
| `modalCheckout` | `src/modal-checkout/index.js` | Modal checkout logic |
| `frequencyBased` | `src/blocks/donate/frequency-based/index.ts` | Donate frequency-based frontend |
| `tiersBased` | `src/blocks/donate/tiers-based/index.ts` | Donate tiers-based frontend |

### Key Dependencies

- **`newspack-scripts`**: Shared toolchain (ESLint, Stylelint, Prettier, Babel, Webpack, commitlint, semantic-release)
- **`newspack-components`**: Shared React UI components
- **`newspack-colors`** / **`newspack-icons`**: Shared color palette and SVG icons
- **`swiper`** (12.x): Carousel slider
- **`redux`** + **`redux-saga`**: Homepage Articles block store

## REST API

All endpoints use the `newspack-blocks/v1` namespace. Controllers live alongside their block code in `src/blocks/`, not in `includes/`.

| Endpoint | Controller | Permission |
|---|---|---|
| `GET /articles` | `WP_REST_Newspack_Articles_Controller` | Public |
| `GET /newspack-blocks-posts` | Same controller | `edit_posts` |
| `GET /newspack-blocks-specific-posts` | Same controller | `edit_posts` |
| `GET /authors` | `WP_REST_Newspack_Authors_Controller` | `edit_posts` |
| `GET /author-list` | `WP_REST_Newspack_Author_List_Controller` | `edit_posts` |
| Various iframe endpoints | `WP_REST_Newspack_Iframe_Controller` | `edit_posts` / custom |

REST controllers are instantiated via wrapper functions hooked to `rest_api_init` in `newspack-blocks.php`.

## PHP Architecture

### Constants

| Constant | Value |
|---|---|
| `NEWSPACK_BLOCKS__PLUGIN_FILE` | Main plugin file path |
| `NEWSPACK_BLOCKS__BLOCKS_DIRECTORY` | `'dist/'` |
| `NEWSPACK_BLOCKS__PLUGIN_DIR` | Plugin directory path |
| `NEWSPACK_BLOCKS__VERSION` | Version string (updated by semantic-release) |

### Key Classes

| Class | File | Purpose |
|---|---|---|
| `Newspack_Blocks` | `includes/class-newspack-blocks.php` | Main facade: registration, assets, query builder, template helpers |
| `Newspack_Blocks_API` | `includes/class-newspack-blocks-api.php` | REST API utilities |
| `Newspack_Blocks_Caching` | `includes/class-newspack-blocks-caching.php` | Object-cache layer |
| `Newspack_Blocks_Patterns` | `includes/class-newspack-blocks-patterns.php` | Block pattern registration |
| `Modal_Checkout` | `includes/class-modal-checkout.php` | WooCommerce modal checkout (~79KB) |
| `Newspack_Blocks_Donate_Renderer` | `src/blocks/donate/frontend/` | Donate SSR with strategy classes |

### Naming Conventions

- **Functions**: `newspack_blocks_*` prefix (e.g., `newspack_blocks_render_block_homepage_articles`)
- **Classes**: `Newspack_Blocks*` or `WP_REST_Newspack_*` for REST controllers
- **Constants**: `NEWSPACK_BLOCKS__*` (double underscore)
- **Hooks**: `newspack_blocks_*` prefix; modal-specific use `newspack_modal_checkout_*`
- **Text domain**: `newspack-blocks`
- **Block names**: Short slugs in `block.json`, namespaced as `newspack-blocks/<slug>` in PHP/JS

### Localized Editor Data

The editor script receives a `newspack_blocks_data` global containing: REST URLs, asset paths, iframe config, reCAPTCHA support, donation/WooCommerce settings, custom taxonomies, block patterns for the current post type, CoAuthors Plus availability, and author custom fields.

## JavaScript Patterns

### React Component Styles

Mixed patterns exist:
- **Newer blocks** use function components + hooks (`author-profile`, `checkout-button`, `donate`, `iframe`)
- **Older blocks** use class components + HOCs (`homepage-articles`, `carousel`, `video-playlist`)

### State Management

- **Block attributes**: Primary persistent state, managed via `setAttributes`.
- **Custom Redux store**: `src/blocks/homepage-articles/store.js` registers a `@wordpress/data` store using Redux + redux-saga. Used by both homepage-articles and carousel for cross-block post deduplication.
- **React Context**: `src/blocks/author-profile/context.js` defines `AuthorContext`, also exposed as `window.NewspackAuthorContext` for cross-package consumption (e.g., by the avatar block in newspack-plugin).
- **Global bridge**: `window.__newspackCurrentAuthor` is used by block bindings to read author data.
- **Frontend state**: Scripts consume `data-*` attributes and localized globals, then mutate DOM/class state (no frontend React).

### SCSS Architecture

- Per-block split: `editor.scss` (editor-only) and `view.scss` (frontend).
- Shared partials via `@use`: `src/shared/sass/_variables.scss`, `_mixins.scss`, `_placeholder.scss`, `_preview.scss`.
- BEM-like naming within WP wrapper classes (e.g., `.wp-block-newspack-blocks-author-profile__bio`).
- Short internal prefixes for legacy blocks: `.wpnbha` (homepage-articles), `.wpnbpc` (carousel).
- No CSS-in-JS.

### TypeScript

Partial adoption. Some blocks use `.ts`/`.tsx` (homepage-articles `edit.tsx`, donate types/views), but most use plain JS. Config extends `newspack-scripts/config/tsconfig.json`. Type declarations live in `src/types/`.

## Testing

### PHP Tests

- **Config**: `phpunit.xml.dist` with a single `main` test suite matching `tests/test-*.php`
- **Bootstrap**: `tests/bootstrap.php` loads the WP test framework and the plugin
- **Base class**: `tests/wp-unittestcase-blocks.php` provides `WP_UnitTestCase_Blocks` with:
  - Mock `CoAuthors_Plus` and `CoAuthors_Guest_Authors` classes
  - Helper methods: `get_args_with_defaults()`, `create_guest_author()`, `create_post()`
  - Registers a custom `author` taxonomy for CoAuthors Plus testing
- **Test files**: `test-donate-block.php`, `test-homepage-posts-block.php`
- **No PHPUnit groups** are currently defined
- **Run**: `n test-php` (from within the repo directory)

### JS Tests

- Minimal: only `src/blocks/donate/tiers-based/utils.test.js`
- Uses Jest via `newspack-scripts test`
- Always run the full suite (`npm test`), not individual files

## Hooks and Extension Points

The plugin exposes many hooks. Rather than listing them all here (they change over time), use `grep -r 'apply_filters\|do_action' src/ includes/` to find current hooks. The key hook prefixes are:

- **`newspack_blocks_*`**: General block hooks (query building, deduplication, registration, author data)
- **`newspack_modal_checkout_*`**: Modal checkout specific hooks (labels, gateways, billing fields, HTML output)

## CI/CD

See parent `../../AGENTS.md` for shared CI conventions. This repo's release runs `newspack-scripts release --files=newspack-blocks.php` to auto-bump the version in the main plugin file header.
