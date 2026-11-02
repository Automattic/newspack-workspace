# Ad Slot / Header Spacing Design

**Date:** 2026-06-09
**Branch:** `feat/add-ad-slot-to-theme`
**Components:** `themes/newspack-block-theme`, `plugins/newspack-ads` (single monorepo, one branch/PR)

## Problem

The header template part (`parts/header.html`) now renders a `global_below_header`
ad slot as the last sibling inside `<header>`, after the desktop and mobile header
parts:

```
<header class="wp-block-template-part">
  [ global_above_header ad ]        (optional)
  <div class="header-desktop"> <div class="wp-block-group" style="margin-bottom: spacing-80"> …nav… </div> </div>
  <div class="header-mobile">  <div class="wp-block-group" style="margin-bottom: spacing-50"> …nav… </div> </div>
  [ global_below_header ad ]        (optional)
</header>
```

The desktop/mobile header groups carry a baked-in `margin-bottom` (`spacing-80`
desktop, `spacing-50` mobile) defined inline in the header patterns
(`patterns/header/header-desktop-style-1..5.php`, `header-mobile-style-1..6.php`).
That margin was the header→content gap from when nothing sat between them.

Now the below-header ad drops *into* that gap, so when an ad is populated:

- there is a huge gap between the desktop header and the ad (the `spacing-80` margin), and
- the ad is jammed against the page content (no gap below it).

## Goal

Reader-facing invariant: **the gap between the header region and the page content
is constant — the ad drops *into* that gap rather than adding to it.** Whether or
not an ad is present, the visual rhythm from "end of header" to "start of content"
should feel the same.

Publisher-facing: keep editing the desktop/mobile header margins exactly as today,
and gain independent margin/padding control on the ad slot itself.

Constraint: the below-header ad is a **single shared slot** for all viewports. We
cannot duplicate it per breakpoint and hide copies with CSS — Google penalizes
hidden/duplicated ad slots.

## Approach

Split ownership of the gap by state:

- **No ad / empty ad** → the header group margin owns the header→content gap (unchanged, publisher-editable).
- **Populated ad** → the header group margin is suppressed and the **ad block** owns the gap below it (new, publisher-editable on the ad block).

This sidesteps the unsolvable "read a dynamic inline margin value and relocate it"
problem. Instead of moving the header's value, we cancel it when an ad is populated
and let the publisher set the ad's own spacing.

The populated/empty state is already reflected in the DOM by newspack-ads' GAM
script (`plugins/newspack-ads/includes/providers/gam/class-gam-scripts.php` ~line 400):

```js
if ( event.isEmpty && ( ad_unit.sticky || ! ad_unit.fixed_height.active || ( … && ! ad_unit.in_viewport ) ) ) {
    container.parentNode.style.display = 'none';   // the .newspack_global_ad wrapper
} else {
    container.parentNode.style.display = 'flex';
}
```

So:

- **Not configured** → block renders `''` → no element at all.
- **Configured + empty (collapsible)** → wrapper gets inline `display: none` → removed from layout.
- **Configured + filled** (or fixed-height reserving space) → `display: flex` → takes space.

CSS keys off that display state.

## Detailed design

### Piece 1 — Conditional removal of the header margin (theme)

When a *populated* below-header ad is present, suppress the header group's bottom
margin so the ad tucks up under the header. `!important` is appropriate here
because ownership of the gap deliberately moves to the ad block.

```scss
// Trigger on populated state, NOT mere presence: an empty ad collapses to
// display:none, and killing the header margin then would glue the header to the
// content. The string match below is coupled to the inline `display` value GAM
// writes via container.parentNode.style.display — do not "clean up" without a
// matching change in newspack-ads.
header:has( > .global_below_header:not( [style*="display: none"] ) ) {
	.header-desktop > .wp-block-group,
	.header-mobile  > .wp-block-group {
		margin-bottom: 0 !important;
	}
}
```

State coverage:

- Configured + filled → `display:flex` → margin killed, ad owns the gap. ✓
- Configured + empty → `display:none` → selector fails → header margin returns. ✓
- Not configured → no element → header margin applies as today. ✓

No media queries needed: the rule zeroes both the desktop and mobile header
groups, and only the viewport's visible header part is in flow (the other is
`display:none` via block visibility), so only the relevant removal has effect.

Location: `src/scss/blocks/newspack/_ad-slot.scss` (keep all ad-driven spacing
logic in one place), or `src/scss/_header.scss`. Decide during implementation.

### Pieces 2 & 3 — Margin + padding controls on the Ad Slot block (newspack-ads)

Enable spacing supports in `plugins/newspack-ads/src/blocks/ad-slot/block.json`:

```json
"supports": {
	"html": false,
	"visibility": false,
	"spacing": { "margin": true, "padding": true }
}
```

The publisher then sets the with-ad gap (and any internal padding) directly on the
ad block.

**Dynamic-block wrinkle to resolve in implementation.** `Ad_Slot_Block::render_block()`
returns raw `do_action()` output, so spacing styles are dropped unless the render
applies them. Two options:

- **Option A — wrap the output** with `get_block_wrapper_attributes()`:
  ```php
  $content = ob_get_clean();
  return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), $content );
  ```
  The spacing lands on the new outer `.wp-block-newspack-ads-ad-slot` wrapper,
  which sits *outside* `.newspack_global_ad` (the element GAM toggles to
  `display:none`). So an empty ad would leave phantom margin/padding on the outer
  wrapper. It must collapse with its empty child:
  ```scss
  .wp-block-newspack-ads-ad-slot:has( > .newspack_global_ad[style*="display: none"] ) {
  	margin: 0 !important;
  	padding: 0 !important;
  }
  ```

- **Option B — push spacing onto `.newspack_global_ad` directly** via the existing
  placement filters/actions in `class-placements.php` (e.g. inject the block's
  spacing inline style/classnames into the wrapper that already gets `display:none`).
  More plumbing, but no extra wrapper and no phantom-space problem because the
  spacing collapses together with the element GAM hides.

**Decision: Option B.** Push the block's spacing onto `.newspack_global_ad`
directly so it collapses together with the element GAM hides — no extra wrapper,
no phantom space, no second `!important` collapse rule. The deciding factor is
keeping the frontend markup close to what it already is: Option A would add a new
`.wp-block-newspack-ads-ad-slot` wrapper element around every placement, whereas
Option B reuses the existing `.newspack_global_ad` div as the spacing carrier.
The slightly more involved plumbing is an acceptable trade for not changing the
DOM structure. Implementation note: the
placement pipeline (`inject_placement_ad` → the `.newspack_global_ad` div in
`class-placements.php`) currently has no channel for block-level spacing — its
only injection points (`newspack_ads_placement_classnames` filter,
`newspack_ads_before/after_placement_ad` actions) are global to every placement.
So this requires adding a scoped mechanism to pass the ad-slot block's spacing
style/classnames through to its placement wrapper (e.g. a new filter/parameter,
or having `render_block()` set per-render spacing that the placement render
consumes). Resolve the exact mechanism during implementation, keeping it scoped
to the block-driven placement so widgets/sidebars/hook placements are unaffected.

## Edge cases

- **Empty ad (collapsible):** GAM sets `display:none`; header margin returns; ad
  spacing collapses. Gap preserved by the header margin. ✓
- **Empty ad with fixed-height reserving space in viewport:** stays `display:flex`;
  treated as populated; ad owns the gap (it visually reserves space anyway). ✓
- **No ad configured:** no element; today's behavior unchanged. ✓
- **More than one below-header ad:** each populated slot is a sibling inside the
  header; the header margin is suppressed once any populated ad follows, and each
  ad block carries its own spacing. Stacked ads space themselves via their own
  block spacing. ✓
- **above_header ad:** unaffected; this design only touches the below-header gap.
- **Initial paint before GAM resolves:** a configured slot renders with no inline
  `display` yet, so it is treated as populated (margin suppressed) until GAM
  collapses it if empty. This rides the ad's existing async render; ads already
  appear/shift asynchronously.

## Files affected

- `themes/newspack-block-theme/src/scss/blocks/newspack/_ad-slot.scss` (or `_header.scss`) — conditional margin removal (+ wrapper-collapse rule if Option A)
- `plugins/newspack-ads/src/blocks/ad-slot/block.json` — spacing supports
- `plugins/newspack-ads/includes/blocks/class-ad-slot-block.php` and/or `includes/class-placements.php` — apply spacing to render (Option A or B)

Build order: build `newspack-ads` first (theme CSS depends on its emitted
markup/classes), then `newspack-block-theme`.

## Testing

Manual, on a site with GAM configured (or an isolated env):

1. Below-header ad **filled**: small/zero gap above the ad, publisher-set gap below it; header→content rhythm matches the no-ad page.
2. Below-header ad **empty/collapsible**: header→content gap looks identical to no-ad.
3. **No** below-header ad: unchanged from current behavior.
4. Desktop **and** mobile breakpoints both correct.
5. Publisher sets margin/padding on the ad block in the site editor → reflected on the frontend; collapses when the ad is empty.
6. Publisher edits the desktop/mobile header group margin → still works for the no-ad case.

Lint: `npm run lint` (JS/SCSS) in the theme; `npm run lint:php` separately for PHP;
build both components.

## Risks / notes

- The `[style*="display: none"]` match is coupled to GAM's inline serialization.
  Documented inline. A cleaner long-term hook would be an explicit
  `is-empty`/`is-filled` class toggle on `.newspack_global_ad` in newspack-ads;
  out of scope for v1 but noted as a follow-up.
- `:has()` is required; baseline browser support is fine as of 2026.
- Spacing supports on the ad slot is a general improvement to the block and
  benefits non-theme usages too — it belongs in newspack-ads, consistent with the
  convention not to add/modify blocks in the theme.
