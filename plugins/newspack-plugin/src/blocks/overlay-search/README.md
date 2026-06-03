# Overlay Search block

A search button (`newspack/overlay-search`) that opens a full-screen overlay containing a search form. Block theme only.

When Jetpack Instant Search is active, the block hands off to Jetpack's own overlay instead of rendering its own.

## Settings
- Trigger text (`triggerText`): Label shown on the button. Also used as the overlay dialog's accessible name. Defaults to "Search".
- Overlay color (`overlayColor`): Background color of the overlay panel. Supports custom colors with transparency (alpha). Set via the color controls in the inspector.

### Display
The block has three styles: **Default** (icon + label), **Icon only**, and **Text only**.

When only the icon is visible, the label is still available to screen readers via a `screen-reader-text` span. To edit a hidden label, switch back to a style that shows it, change the text, then switch back.

Standard button block supports are available too: text/background color, typography, padding, border radius, and shadow.

## Editor behavior
- The trigger button is rendered as a preview; the overlay panel itself is not shown in the editor (it's rendered on the front end).
- Edit the label inline on the button. The search icon is fixed and not configurable.

## Front-end behavior
- Clicking the trigger opens a full-screen overlay containing a `core/search` form, with focus moved into the search input.
- The overlay is a focus-trapped dialog: Tab cycles within it, Escape closes it, and clicking the background (scrim) or the close button closes it too. Focus returns to the trigger on close.
- Each block instance is independent — a trigger only opens its own overlay.
- If Jetpack Instant Search is active, the trigger instead links to site search as a `.jetpack-search-filter__link`, and Jetpack's script provides the overlay. No Newspack panel is rendered in this case.

## Notes
- Block theme only. Registration is gated on `wp_is_block_theme()` (PHP) and `newspack_blocks.is_block_theme` (JS).
- On open, the panel is moved to `document.body` to avoid stacking-context issues, and a `menu-open--overlay-search-{id}` class is added to `<body>` to lock page scrolling. Because the panel leaves its original ancestor, its styles are written at root scope rather than nested under the block wrapper — the same approach as the [Overlay Menu block](../overlay-menu/README.md).
- The close-button icon color is chosen at runtime for contrast against the overlay background, so it stays legible with any `overlayColor`.
