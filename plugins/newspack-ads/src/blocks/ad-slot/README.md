# Ad Slot Block

A Gutenberg block that marks where a global ad placement (above header, sticky footer, and so on) renders in a block theme. The block chooses only the placement. The ad unit for that placement is set in the Advertising wizard, the same as in classic themes.

## Overview

`newspack-ads/ad-slot` is a single dynamic block with no inner blocks, intended for template parts and templates in the Site Editor.

In classic themes, global placements render on hooks such as `wp_body_open`, plus `after_header` and `before_footer`, which come from the classic Newspack theme. Block themes don't fire those theme hooks and don't let a hook choose a position inside a template part. So when the active theme is a block theme, `Placements::register_default_placements()` registers the placements with hooks that only this block fires. Publishers keep managing ad units per placement in the wizard; the block decides where each placement appears in the template.

Four of the block placements reuse the classic keys (`global_above_header`, `global_below_header`, `global_above_footer`, `sticky`). Wizard settings are stored per key, so ad units set under a classic theme carry over when a site switches to a block theme. Renaming a key drops the ad unit saved for it. `above_content` and `below_content` are block-theme only.

For an ad unit chosen per block, in post content or anywhere else, use the Ad Unit block (`newspack-ads/ad-unit`) instead.

## Block attributes

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `placement` | string | `""` | Key of the placement this block renders; see [Placements](#placements). The value isn't validated: an empty or unknown key renders nothing. |

**Block supports:** `spacing.padding`, `spacing.margin`. `html` and `visibility` are off.

## Placements

| Key | Name | Where the block theme places it |
|-----|------|---------------------------------|
| `global_above_header` | Above Header | `parts/header.html` |
| `global_below_header` | Below Header | `parts/header.html` |
| `global_above_footer` | Above Footer | `parts/footer.html` |
| `sticky` | Sticky Footer | `parts/footer.html` |
| `above_content` | Above Content | Single post templates |
| `below_content` | Below Content | Single post templates |

These are defined in `Placements::register_block_placements()`. A placement needs two things to work with this block: `block_rendered: true`, which puts it in the editor's placement list, and a `hook_name` from `Placements::get_block_hook_name()` (`newspack_ads_block_placement_<key>`), which is the hook the block fires to render it. Both go through `Placements::register_placement()`, which rejects a key that's already registered.

## Availability

Block theme only. The block itself is registered in every theme so saved instances stay valid, but in a classic theme the block placements aren't registered, so the block renders nothing. `register_default_placements()` checks `wp_is_block_theme()`, which the `newspack_ads_is_block_theme` filter can override (for tests, for example). The same filter also turns off sidebar (widget area) placements, so forcing it on has both effects.

The block only appears in the inserter in the Site Editor (`site-editor.php`). It stays registered in the post editor so existing instances still load there.

## Editor behavior

**Placement list:** `edit.js` fetches `/newspack-ads/v1/placements` and lists the placements with `block_rendered: true`. The endpoint requires `manage_options` (filterable through `newspack_ads_can_current_user_manage_settings`). A user who can open the Site Editor without that capability sees an empty list with no error, and a saved block shows its placement key instead of its name. With no placement selected, the block shows a placeholder with a select field. The same field is always available in the sidebar under **Placement**.

**Preview:** Once a placement is selected, the block shows an outlined box with the placement name in a label. It reuses the `.newspack-ads-ad-block-placeholder` styles from the Ad Unit block's `editor.scss`, which only load because both blocks share the `editor` bundle. It adds a minimum height of its own, since the block doesn't know the ad unit's sizes at edit time. No live ad loads in the editor.

## Rendering

`save: () => null`. All markup comes from `Ad_Slot_Block::render_block()` in `includes/blocks/class-ad-slot-block.php`.

The block fires its placement's hook, and `Placements::inject_placement_ad()` outputs the ad for the unit set in the wizard. When there's nothing to show (a classic theme, an unknown key, no ad unit set, ads suppressed, or an inactive provider), the block outputs no markup. That lets a template carry every placement without leaving empty elements on sites that don't use them all.

The block adds no wrapper element of its own. The block theme's header styles select the ad container as a sibling (`.wp-block-newspack-responsive-container:has( ~ .global_below_header )` in `_header.scss`), so a wrapper would break them. `apply_spacing()` merges the block's padding and margin onto the first element of the ad markup (the `newspack_global_ad` container), skipping any leading `<style>` or `<script>` tags. Fixed-height GAM placements print a `<style>` block first, so without that skip the spacing would land on the wrong element.

## Usage with block theme templates

The block is placed in header and footer template parts and in single post templates. The header and footer starter patterns (`patterns/header/header-starter.php`, `patterns/footer/footer-starter.php`) show it in context. Example markup:

```html
<!-- wp:newspack-ads/ad-slot {"placement":"global_above_header"} /-->
<!-- wp:newspack-ads/ad-slot {"placement":"sticky"} /-->
```

## Related

- [`includes/blocks/class-ad-slot-block.php`](../../../includes/blocks/class-ad-slot-block.php): Block registration and PHP render callback.
- [`includes/class-placements.php`](../../../includes/class-placements.php): `register_default_placements()`, `register_block_placements()`, `get_block_hook_name()`, and `inject_placement_ad()`.
- [Ad Unit block](../ad-unit/): Block for a specific ad unit, and source of the shared editor preview styles.
