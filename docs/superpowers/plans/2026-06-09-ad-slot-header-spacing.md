# Ad Slot / Header Spacing — Implementation Plan (light)

**Goal:** Make the below-header ad drop *into* the header→content gap instead of adding to it, while giving publishers margin/padding control on the ad slot block.

**Approach:** Header group margin owns the no-ad gap (unchanged). When a *populated* below-header ad is present, theme CSS suppresses that margin and the ad block's own spacing owns the gap. Spacing is forwarded onto the existing `.newspack_global_ad` wrapper (Option B — no new markup).

**Spec:** `docs/superpowers/specs/2026-06-09-ad-slot-header-spacing-design.md`

**Build order:** newspack-ads first, then newspack-block-theme (theme CSS depends on the ads markup). All one branch: `feat/add-ad-slot-to-theme`.

---

## Task 1 — Spacing supports on the ad slot block (newspack-ads)

**File:** `plugins/newspack-ads/src/blocks/ad-slot/block.json`

- [ ] Add spacing supports:

```json
"supports": {
	"html": false,
	"visibility": false,
	"spacing": { "margin": true, "padding": true }
}
```

## Task 2 — Filterable inline style on the placement wrapper (newspack-ads)

**File:** `plugins/newspack-ads/includes/class-placements.php` (the wrapper `<div>` ~line 839, inside `inject_placement_ad()`)

- [ ] Just before the `<div class='…'>` is printed, compute a filtered inline style (empty by default, so all existing placements are unaffected):

```php
$inline_style = apply_filters( 'newspack_ads_placement_inline_style', '', $placement_key, $hook_key, $placement_data );
```

- [ ] Output it on the wrapper:

```php
<div class='<?php echo esc_attr( $classnames_str ); ?>'<?php echo $inline_style ? ' style="' . esc_attr( $inline_style ) . '"' : ''; ?>>
```

## Task 3 — Forward block spacing onto the wrapper (newspack-ads)

**File:** `plugins/newspack-ads/includes/blocks/class-ad-slot-block.php` (`render_block()`)

- [ ] Replace `render_block()` so it derives spacing CSS from the block's `style.spacing` and injects it via the filter, scoped to this single render (added then immediately removed, so widget/sidebar/hook placements never see it):

```php
public static function render_block( $attributes, $content = '', $block = null ) {
	if ( empty( $attributes['placement'] ) ) {
		return '';
	}
	$hook_name = Placements::get_block_hook_name( $attributes['placement'] );
	if ( ! has_action( $hook_name ) ) {
		return '';
	}

	// Forward the block's margin/padding onto the .newspack_global_ad wrapper so
	// it collapses together with the ad when GAM hides an empty slot. Scoped to
	// this render only — the filter is removed immediately after.
	$spacing_css = '';
	$spacing     = $attributes['style']['spacing'] ?? [];
	if ( ! empty( $spacing ) ) {
		$engine_styles = wp_style_engine_get_styles( [ 'spacing' => $spacing ] );
		$spacing_css   = $engine_styles['css'] ?? '';
	}
	$inject = static function () use ( $spacing_css ) {
		return $spacing_css;
	};
	if ( $spacing_css ) {
		add_filter( 'newspack_ads_placement_inline_style', $inject );
	}

	ob_start();
	do_action( $hook_name );
	$output = ob_get_clean();

	if ( $spacing_css ) {
		remove_filter( 'newspack_ads_placement_inline_style', $inject );
	}

	return $output;
}
```

## Task 4 — Test the spacing is forwarded (newspack-ads)

**File:** `plugins/newspack-ads/tests/test-ad-slot-block.php`

- [ ] Add a test that plants a listener which echoes the filtered inline style, then asserts the block forwarded its spacing as CSS (mirrors how the real wrapper consumes the filter):

```php
/**
 * The block forwards its spacing attributes onto the placement wrapper via filter.
 */
public function test_render_forwards_block_spacing() {
	add_filter( 'newspack_ads_is_block_theme', '__return_true' );
	$ref  = new \ReflectionClass( \Newspack_Ads\Placements::class );
	$prop = $ref->getProperty( 'placements' );
	$prop->setAccessible( true );
	$prop->setValue( null, [] );
	\Newspack_Ads\Placements::register_default_placements();

	$listener = function () {
		echo esc_attr( apply_filters( 'newspack_ads_placement_inline_style', '', 'global_above_header', '', [] ) );
	};
	add_action( 'newspack_ads_block_placement_global_above_header', $listener, 999 );

	$html = \Newspack_Ads\Ad_Slot_Block::render_block(
		[
			'placement' => 'global_above_header',
			'style'     => [ 'spacing' => [ 'margin' => [ 'bottom' => 'var:preset|spacing|80' ] ] ],
		]
	);
	self::assertStringContainsString( 'margin-bottom:var(--wp--preset--spacing--80)', $html );

	remove_action( 'newspack_ads_block_placement_global_above_header', $listener, 999 );
	remove_filter( 'newspack_ads_is_block_theme', '__return_true' );
}
```

- [ ] Run: `cd plugins/newspack-ads && n test-php --filter test_render_forwards_block_spacing` → PASS.

## Task 5 — Conditional header-margin removal (newspack-block-theme)

**File:** `themes/newspack-block-theme/src/scss/blocks/newspack/_ad-slot.scss`

- [ ] Append:

```scss
// When a populated below-header ad sits inside the header, the desktop/mobile
// header group's baked-in bottom margin would otherwise push the ad down and
// leave it jammed against the content. Suppress it so the ad tucks under the
// header; the ad block's own spacing then owns the gap to the content.
//
// Keyed on the ad being POPULATED, not merely present: GAM collapses an empty
// slot to `display: none`, so an empty/absent ad keeps the header margin intact.
// The [style] match tracks the inline display GAM writes via
// container.parentNode.style.display — do not "tidy" it without a matching
// change in newspack-ads.
header:has( > .global_below_header:not( [style*="display: none"] ) ) {
	.header-desktop > .wp-block-group,
	.header-mobile > .wp-block-group {
		margin-bottom: 0 !important;
	}
}
```

## Wrap-up

- [ ] Build both: `cd plugins/newspack-ads && n build` then `cd themes/newspack-block-theme && n build`.
- [ ] Lint: in the theme, `npm run lint` and `npm run lint:php`; in newspack-ads, `npm run lint` / `npm run lint:php` as applicable.
- [ ] Manual verify on a GAM-configured site / isolated env:
  - Below-header ad **filled** → ad tucks under the header, publisher's gap below it, header→content rhythm matches a no-ad page.
  - Below-header ad **empty** → looks identical to no-ad (header margin returns).
  - **No** ad configured → unchanged.
  - Desktop **and** mobile both correct.
  - Set margin/padding on the ad block in the site editor → reflected on the frontend; collapses when the ad is empty.
- [ ] Do **not** commit the spec/plan docs (kept out of the PR per request).
