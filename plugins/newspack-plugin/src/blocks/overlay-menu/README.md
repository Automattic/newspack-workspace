# Overlay Menu Block

A Gutenberg block that pairs a trigger button with a content panel. The panel can appear as a side overlay or expand to fill the entire screen.

## Overview

The Overlay Menu is a three-block system: a parent container (`newspack/overlay-menu`) that holds an Overlay Button (`newspack/overlay-menu-trigger`) and a Menu Panel (`newspack/overlay-menu-panel`). The parent is `templateLock="all"` — both child blocks are always present and cannot be removed or reordered. The block is only available in block themes.

Each instance gets a unique `instanceId` derived from its `clientId`. The parent provides this via block context (`newspack-overlay-menu/instanceId`), and both children consume it to link the trigger to the panel via ARIA and to scope body classes and panel IDs on the frontend. The parent PHP renderer writes the `instanceId` as a `data-overlay-id` attribute on the wrapper element; `view.js` reads `wrapper.dataset.overlayId` to locate the associated panel.

Multiple instances on the same page are fully independent.

## Block attributes

### `newspack/overlay-menu` (parent)

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `instanceId` | string | `""` | Unique identifier scoped to this block instance. Derived from `clientId` on first render and synced to attributes so it survives duplication. Provided to children via context. |

**Block supports:** `anchor`, `spacing.margin`.

### `newspack/overlay-menu-trigger` (Overlay Button)

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `triggerText` | string | `"Menu"` | Label text on the trigger button. Always present in the DOM; may be visually hidden depending on the active block style. |

**Block styles:**

| Style | Description |
|-------|-------------|
| Default | Hamburger icon + label text |
| Icon only | Icon only; label hidden with `screen-reader-text` |
| Text only | Label only; no icon |

**Block supports:** `color.text`, `color.background`, `typography.fontSize`, `spacing.padding`, `border.radius`.

### `newspack/overlay-menu-panel` (Menu Panel)

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `slideDirection` | string | `"left"` | Which side the panel slides in from. Accepted values: `"left"`, `"right"`. Hidden in the editor when `isFullScreen` is `true`. |
| `isFullScreen` | boolean | `false` | When `true`, the panel expands to fill the entire viewport. Hides the `slideDirection` and `panelWidth` controls. |
| `panelWidth` | string | `"small"` | Panel width when not full screen. Accepted values: `"x-small"`, `"small"`, `"medium"`, `"large"`, `"x-large"`. Hidden in the editor when `isFullScreen` is `true`. |
| `overlayColor` | string | `""` | Color of the overlay backdrop. Supports RGBA for transparency. Read by `view.js` from a `data-overlay-color` attribute on the panel element. |
| `panelBackgroundColor` | string | `""` | Panel background color. Applied as an inline style. |
| `panelTextColor` | string | `""` | Panel text color. Applied as an inline style. |

## Editor behavior

**Preview toggle**: All three blocks — parent, trigger, and panel — include a `PanelPreviewToggle` button (shared component at `panel-preview-toggle.js`). Activating it opens the panel in the editor for design purposes without navigating to the frontend.

Preview open/close state is managed as local React state inside `panel/edit.js` and coordinated across blocks via `preview-refs.js`, a module-level pub/sub system. `preview-refs.js` exports one Map keyed by panel `clientId`:
- `panelToggles` — the panel registers a toggle function here so sibling/parent blocks can call it without shared reactive store or block attributes.

Subscriber state is kept in a module-private `subscribers` Map. External code interacts with it only through the exported `subscribeToPanel()` (to register a React state setter) and `notifySubscribers()` (to broadcast state changes). Multiple blocks can subscribe to the same panel.

The preview state is entirely ephemeral and is never persisted to block attributes or saved markup.

**Trigger edit**: `triggerText` is edited inline via a plain-text `RichText` field inside the button. The icon is not configurable from the editor.

**Panel colors**: The panel's sidebar uses `ColorGradientSettingsDropdown` for text, background, and overlay color, supporting the theme palette and custom colors including alpha.

## Rendering

All three blocks are dynamic — each has a PHP render callback.

**Parent block** (`class-overlay-menu-block.php`): Renders the outer wrapper. Adds `data-overlay-id` (the `instanceId`) as a data attribute so `view.js` can locate each instance's panel at runtime.

**Trigger block** (`class-overlay-menu-trigger-block.php`): `save: () => null` — no static markup is saved. The PHP callback renders the button with `aria-expanded="false"`, `aria-controls`, and `aria-label`. Icon visibility and label class follow the active block style (`is-style-icon-only`, `is-style-text-only`).

**Panel block** (`class-overlay-menu-panel-block.php`): `save()` persists a minimal wrapper `<div>` containing the `InnerBlocks` content. The PHP callback builds the panel wrapper with ARIA attributes (`role="dialog"`, `aria-modal="true"`, `aria-hidden="true"`, `inert="true"`, `aria-label`), reads `instanceId` from block context, and outputs InnerBlocks content. The `data-overlay-color` attribute on the panel wrapper is used by `view.js` to set the scrim color at runtime.

**Frontend script** (`view.js`, webpack entry `overlay-menu-block`): Creates a self-contained controller per instance (`createFlyoutInstance`). It locates each instance by `[data-overlay-id]` on the wrapper element. On open, the panel is moved to `document.body` (to avoid stacking context issues from CSS transforms), ARIA states are updated (`aria-expanded`, `aria-hidden`, `inert` toggled), a focus trap is activated, and a scrim element is created dynamically. A `menu-open--overlay-menu-{instanceId}` class is added to `<body>` while open. Escape closes the menu. Focus returns to the trigger on close.

**CSS note**: Panel and overlay styles are written at root scope (not nested inside the block wrapper selector) because the panel moves to `document.body` on open and loses its ancestor context.

## Availability

Block theme only. Registration in `includes/class-blocks.php` is conditional on `wp_is_block_theme()`.

## Usage with block theme patterns

The block is typically placed in a header template part. Example markup:

```html
<!-- wp:newspack/overlay-menu -->
<div class="wp-block-newspack-overlay-menu">
  <!-- wp:newspack/overlay-menu-trigger {"triggerText":"Menu"} /-->
  <!-- wp:newspack/overlay-menu-panel {"slideDirection":"left"} -->
  <div class="wp-block-newspack-overlay-menu-panel">
    <!-- wp:navigation {"overlayMenu":"never","layout":{"type":"flex","orientation":"vertical"}} /-->
  </div>
  <!-- /wp:newspack/overlay-menu-panel -->
</div>
<!-- /wp:newspack/overlay-menu -->
```

## Related

- [Overlay Menu block source](.) — Parent block, `view.js` frontend controller, `panel-preview-toggle.js` shared component, and `preview-refs.js` pub/sub module.
- [Trigger block source](./trigger/) — Button sub-block with style variations and PHP renderer.
- [Panel block source](./panel/) — Drawer panel sub-block with color controls and PHP renderer.
- [`includes/class-blocks.php`](../../../includes/class-blocks.php) — Conditional block registration (block theme check).
