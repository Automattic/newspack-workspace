# Ad Slot Block

A Gutenberg block that marks where a global ad placement (above header, sticky footer, and so on) renders in a block theme. The block chooses only the placement. The ad unit for that placement is set in the Ads wizard, the same as in classic themes.

## Overview

`newspack-ads/ad-slot` is a single dynamic block with no inner blocks, intended for template parts and templates in the Site Editor.

Classic themes render global placements by firing theme hooks such as `after_header` and `before_footer`. Block themes have no equivalent hooks, so when the active theme is a block theme, `Placements::register_default_placements()` registers a separate set of placements whose hooks only this block fires. Publishers keep managing ad units per placement in the wizard; the block decides where each placement appears in the template.

For an ad unit chosen per block, in post content or anywhere else, use the Ad Unit block (`newspack-ads/ad-unit`) instead.

## Block attributes

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `placement` | string | `""` | Key of the placement this block renders. Accepted values: `"global_above_header"`, `"global_below_header"`, `"global_above_footer"`, `"sticky"`, `"above_content"`, `"below_content"`. The block renders nothing when empty. |

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

These are defined in `Placements::register_block_placements()`. Each one is registered with `block_rendered: true` and a hook from `Placements::get_block_hook_name()` (`newspack_ads_block_placement_<key>`). To add a placement to the block, register it there.

## Availability

Block theme only. The block itself is registered in every theme so saved instances stay valid, but in a classic theme the block placements aren't registered, so the block renders nothing. `register_default_placements()` checks `wp_is_block_theme()`, which the `newspack_ads_is_block_theme` filter can override (for tests, for example).

The block only appears in the inserter in the Site Editor (`site-editor.php`). It stays registered in the post editor so existing instances still load there.

## Editor behavior

**Placement list:** `edit.js` fetches `/newspack-ads/v1/placements` and lists only placements with `block_rendered: true` and `show_ui` not set to `false`. With no placement selected, the block shows a placeholder with a select field. The same field is always available in the sidebar under **Placement**.

**Preview:** Once a placement is selected, the block shows a dashed box with the placement name. It reuses the `.newspack-ads-ad-block-placeholder` styles from the Ad Unit block's `editor.scss`, with an 80px minimum height, since the block doesn't know the ad unit's sizes at edit time. No live ad loads in the editor.

## Rendering

`save: () => null`. All markup comes from `Ad_Slot_Block::render_block()` in `includes/blocks/class-ad-slot-block.php`.

1. If `placement` is empty, or nothing is hooked to the placement's hook (a classic theme or an unknown key), the block returns an empty string.
2. Otherwise it fires the hook. `Placements::inject_placement_ad()` checks whether the ad should show and outputs the provider's ad code for the ad unit set in the wizard.
3. If the hook outputs nothing (no ad unit set, ads suppressed, or the provider is inactive), the block returns an empty string.

The block adds no wrapper element of its own. `apply_spacing()` merges the block's padding and margin onto the first element of the ad markup, skipping any leading `<style>` or `<script>` tags. Fixed-height GAM placements print a `<style>` block first, so without that skip the spacing would land on the wrong element.

## Usage with block theme templates

The block is placed in header and footer template parts and in single post templates. Example markup:

```html
<!-- wp:newspack-ads/ad-slot {"placement":"global_above_header"} /-->
<!-- wp:newspack-ads/ad-slot {"placement":"sticky"} /-->
```

## Related

- [`includes/blocks/class-ad-slot-block.php`](../../../includes/blocks/class-ad-slot-block.php): Block registration and PHP render callback.
- [`includes/class-placements.php`](../../../includes/class-placements.php): `register_default_placements()`, `register_block_placements()`, `get_block_hook_name()`, and `inject_placement_ad()`.
- [Ad Unit block](../ad-unit/): Block for a specific ad unit, and source of the shared editor preview styles.
