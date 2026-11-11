# SCAIP RTC Compatibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make SCAIP compatible with WordPress 7.0 real-time collaboration by replacing the classic meta box on block-editor screens with a `PluginDocumentSettingPanel`, while preserving the classic meta box for classic-editor users.

**Architecture:** Migrate the `scaip_prevent_shortcode_addition` post meta to `register_post_meta()` with `show_in_rest => true` and an `auth_callback`. Skip classic meta box registration on block-editor screens via a `get_current_screen()->is_block_editor()` check. Add a vanilla-JS sidebar panel (no JSX, no build step — matching the plugin's existing posture) that binds to the registered meta via `useEntityProp`, which routes through the entity store RTC syncs.

**Tech Stack:** PHP (WordPress plugin), vanilla JS using `wp.element.createElement`, PHPUnit via `n test-php`.

**Working directory for all tasks:** `/Users/laurelfulford/newspack-workspace/repos/super-cool-ad-inserter-plugin`

**Test command:** From inside the SCAIP repo folder, run `n test-php`. The `n` script auto-targets the right container.

---

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `inc/scaip-metaboxes.php` | Modify | Replace `register_meta()` with `register_post_meta()`; refactor inline metabox closure into named function with screen check; add new asset enqueue function; remove unused sanitize helper. |
| `blocks/scaip-document-panel/panel.js` | Create | Block-editor sidebar panel: descriptive paragraph + checkbox bound to the registered meta via `useEntityProp`. |
| `tests/inc/test-scaip-metaboxes.php` | Create | PHPUnit tests for the auth callback and meta registration. |

---

## Task 1: Add PHPUnit test scaffold for the new auth callback

**Files:**
- Create: `tests/inc/test-scaip-metaboxes.php`

- [ ] **Step 1: Create the test file with two failing auth-callback tests**

Create `tests/inc/test-scaip-metaboxes.php`:

```php
<?php
/**
 * Tests for the SCAIP metaboxes / sidebar panel module.
 *
 * @package super-cool-ad-inserter-plugin
 */

/**
 * Test the auth callback used by register_post_meta for
 * scaip_prevent_shortcode_addition.
 */
class ScaipMetaboxesTestFunctions extends WP_UnitTestCase {

	/**
	 * Authors do not have edit_others_posts, so the meta auth callback
	 * must reject their REST writes.
	 */
	public function test_auth_callback_denies_users_without_edit_others_posts() {
		$author_id = $this->factory->user->create( array( 'role' => 'author' ) );

		$result = scaip_prevent_shortcode_addition_auth_callback(
			false,
			'scaip_prevent_shortcode_addition',
			0,
			$author_id
		);

		$this->assertFalse( $result );
	}

	/**
	 * Editors have edit_others_posts, so the meta auth callback must
	 * allow their REST writes.
	 */
	public function test_auth_callback_allows_users_with_edit_others_posts() {
		$editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		$result = scaip_prevent_shortcode_addition_auth_callback(
			false,
			'scaip_prevent_shortcode_addition',
			0,
			$editor_id
		);

		$this->assertTrue( $result );
	}
}
```

- [ ] **Step 2: Run tests and confirm they fail**

```
n test-php --filter ScaipMetaboxesTestFunctions
```

Expected: both tests fail with `Call to undefined function scaip_prevent_shortcode_addition_auth_callback()`. The function doesn't exist yet — that's the point.

- [ ] **Step 3: Commit the failing tests**

```
git add tests/inc/test-scaip-metaboxes.php
git commit -m "test: add failing auth callback tests for scaip post meta"
```

---

## Task 2: Replace `register_meta()` with `register_post_meta()` and add named auth callback

**Files:**
- Modify: `inc/scaip-metaboxes.php` (lines 63-80)

- [ ] **Step 1: Replace the `register_meta()` call and `scaip_prevent_shortcode_addition_sanitize` function**

In `inc/scaip-metaboxes.php`, find this block:

```php
// But always register the meta.
register_meta( 'post', 'scaip_prevent_shortcode_addition', array( 'sanitize_callback' => 'scaip_prevent_shortcode_addition_sanitize' ) );

/**
 * Sanitization callback for saving the scaip_prevent_shortcode_addition post meta option.
 *
 * @param array $args the callback args.
 */
function scaip_prevent_shortcode_addition_sanitize( $args ) {
	$args = sanitize_text_field( $args );
	if ( 1 === intval( $args ) ) {
		$ret = $args;
	} else {
		$ret = false;
	}

	return $ret;
}
```

Replace it with:

```php
/**
 * Auth callback used by register_post_meta for scaip_prevent_shortcode_addition.
 *
 * Only users who can edit_others_posts may toggle this meta via the REST API.
 *
 * @param bool   $allowed   Whether the user can edit the meta. Unused; we make
 *                          the determination ourselves.
 * @param string $meta_key  The meta key being modified. Unused.
 * @param int    $object_id The post being modified. Unused; the cap is global.
 * @param int    $user_id   The user attempting to write the meta.
 * @return bool Whether the write should be permitted.
 */
function scaip_prevent_shortcode_addition_auth_callback( $allowed, $meta_key, $object_id, $user_id ) {
	return user_can( $user_id, 'edit_others_posts' );
}

register_post_meta(
	'post',
	'scaip_prevent_shortcode_addition',
	array(
		'type'          => 'boolean',
		'single'        => true,
		'show_in_rest'  => true,
		'default'       => false,
		'auth_callback' => 'scaip_prevent_shortcode_addition_auth_callback',
	)
);
```

- [ ] **Step 2: Run the auth callback tests and confirm they pass**

```
n test-php --filter ScaipMetaboxesTestFunctions
```

Expected: both tests PASS.

- [ ] **Step 3: Run the full test suite to confirm no regressions**

```
n test-php
```

Expected: existing tests continue to pass (most are `markTestIncomplete`; verify nothing went red).

- [ ] **Step 4: Commit**

```
git add inc/scaip-metaboxes.php
git commit -m "feat: register scaip post meta with show_in_rest for RTC support"
```

---

## Task 3: Refactor inline metabox-registration closure to a named function with a screen check

**Files:**
- Modify: `inc/scaip-metaboxes.php` (lines 53-61)

- [ ] **Step 1: Replace the anonymous `add_meta_boxes` callback**

In `inc/scaip-metaboxes.php`, find this block:

```php
// Only register the meta box if the user is an editor or greater.
add_action(
	'add_meta_boxes',
	function() {
		if ( current_user_can( 'edit_others_posts' ) ) {
			add_meta_box( 'scaip_docs_and_options', __( 'Super Cool Ad Inserter', 'scaip' ), 'scaip_how_to_shortcode_callback', 'post', 'normal', 'low' );
		}
	}
);
```

Replace it with:

```php
/**
 * Registers the classic SCAIP meta box on classic-editor post screens.
 *
 * Skips registration on block-editor screens — the block editor uses the
 * scaip-document-panel sidebar panel, and registering a classic meta box
 * here would disable WordPress 7.0 real-time collaboration.
 */
function scaip_register_classic_metabox() {
	$screen = get_current_screen();
	if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
		return;
	}

	if ( current_user_can( 'edit_others_posts' ) ) {
		add_meta_box(
			'scaip_docs_and_options',
			__( 'Super Cool Ad Inserter', 'scaip' ),
			'scaip_how_to_shortcode_callback',
			'post',
			'normal',
			'low'
		);
	}
}
add_action( 'add_meta_boxes', 'scaip_register_classic_metabox' );
```

- [ ] **Step 2: Run the full test suite to confirm no regressions**

```
n test-php
```

Expected: all tests still pass. (Behavior is unchanged on classic screens; block-editor screens are not exercised by phpunit.)

- [ ] **Step 3: Commit**

```
git add inc/scaip-metaboxes.php
git commit -m "refactor: skip scaip classic meta box on block editor screens"
```

---

## Task 4: Create the block-editor sidebar panel JS

**Files:**
- Create: `blocks/scaip-document-panel/panel.js`

- [ ] **Step 1: Create the panel.js file**

Create `blocks/scaip-document-panel/panel.js`:

```js
( function ( wp ) {
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var el = wp.element.createElement;
	var registerPlugin = wp.plugins.registerPlugin;
	// PluginDocumentSettingPanel moved from wp.editPost to wp.editor in WP 6.6+.
	// Fall back to wp.editPost for older Gutenberg versions.
	var PluginDocumentSettingPanel =
		( wp.editor && wp.editor.PluginDocumentSettingPanel ) ||
		( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	var CheckboxControl = wp.components.CheckboxControl;
	var useEntityProp = wp.coreData.useEntityProp;
	var useSelect = wp.data.useSelect;

	function ScaipDocumentPanel() {
		var postType = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostType();
		}, [] );

		// The classic metabox is registered only on 'post'; mirror that here.
		if ( postType !== 'post' ) {
			return null;
		}

		var entityProp = useEntityProp( 'postType', postType, 'meta' );
		var meta = entityProp[ 0 ] || {};
		var setMeta = entityProp[ 1 ];

		var settings = window.scaipDocumentPanel || {};

		// Mirrors the classic metabox copy in scaip_how_to_shortcode_callback().
		var description = sprintf(
			/* translators: 1: number of ads inserted, 2: blocks before first insertion, 3: blocks between insertions, 4: minimum paragraphs required. */
			__( 'By default, %1$s ads will be inserted in a post, beginning %2$s blocks after the beginning and every %3$s paragraphs after that. They will not appear if this post is shorter than %4$s paragraphs long.', 'scaip' ),
			settings.repetitions,
			settings.start,
			settings.period,
			settings.minimum_paragraphs
		);

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'scaip-document-panel',
				title: __( 'Super Cool Ad Inserter', 'scaip' ),
			},
			el( 'p', null, description ),
			el( CheckboxControl, {
				label: __( 'Prevent automatic addition of ads to this post.', 'scaip' ),
				checked: !! meta.scaip_prevent_shortcode_addition,
				onChange: function ( value ) {
					setMeta(
						Object.assign( {}, meta, {
							scaip_prevent_shortcode_addition: value,
						} )
					);
				},
			} )
		);
	}

	registerPlugin( 'scaip-document-panel', {
		render: ScaipDocumentPanel,
	} );
} )( window.wp );
```

- [ ] **Step 2: Verify the file was created**

```
ls blocks/scaip-document-panel/panel.js
```

Expected: file exists.

- [ ] **Step 3: Commit**

```
git add blocks/scaip-document-panel/panel.js
git commit -m "feat: add block editor sidebar panel for scaip prevent-ads toggle"
```

(No automated JS test — the plugin has no JS test infrastructure and explicitly avoids a build step. Manual verification happens in Task 6.)

---

## Task 5: Enqueue the panel JS for the block editor

**Files:**
- Modify: `inc/scaip-metaboxes.php` (append at end of file)

- [ ] **Step 1: Add the enqueue function**

Append to the end of `inc/scaip-metaboxes.php` (after the existing `add_action( 'save_post', '_scaip_meta_box_save', 10, 2 );` line):

```php
/**
 * Enqueues the SCAIP document settings panel script in the block editor.
 *
 * Skips entirely if the current post is not 'post' or if the user lacks
 * edit_others_posts — the panel JS is never sent to clients that can't use it.
 */
function scaip_enqueue_document_panel_assets() {
	global $post;

	if ( ! $post || 'post' !== $post->post_type ) {
		return;
	}

	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return;
	}

	$relative = '../blocks/scaip-document-panel/panel.js';
	$absolute = __DIR__ . '/' . $relative;

	wp_enqueue_script(
		'scaip-document-panel',
		plugins_url( $relative, __FILE__ ),
		array(
			'wp-plugins',
			'wp-editor',
			'wp-edit-post',
			'wp-element',
			'wp-components',
			'wp-i18n',
			'wp-core-data',
			'wp-data',
		),
		filemtime( $absolute ),
		true
	);

	wp_localize_script(
		'scaip-document-panel',
		'scaipDocumentPanel',
		array(
			'start'              => get_option( 'scaip_settings_start', 3 ),
			'period'             => get_option( 'scaip_settings_period', 3 ),
			'repetitions'        => get_option( 'scaip_settings_repetitions', 2 ),
			'minimum_paragraphs' => get_option( 'scaip_settings_min_paragraphs', 6 ),
		)
	);
}
add_action( 'enqueue_block_editor_assets', 'scaip_enqueue_document_panel_assets' );
```

(Both `wp-editor` and `wp-edit-post` are listed as deps — the panel JS falls back between the two for `PluginDocumentSettingPanel`, so both must be enqueued.)

- [ ] **Step 2: Run the full test suite**

```
n test-php
```

Expected: all tests still pass.

- [ ] **Step 3: Commit**

```
git add inc/scaip-metaboxes.php
git commit -m "feat: enqueue scaip document panel script in block editor"
```

---

## Task 6: Manual / integration verification

These checks cannot be automated cheaply (no JS test harness, screen state requires real admin context). Run them in the workspace's main site at https://lfulford.ngrok.io/ before declaring done.

- [ ] **Step 1: Block editor — sidebar panel renders for editor user**

1. Log in as an Editor (or Administrator).
2. Open any post in the block editor.
3. Open the document settings sidebar (gear icon).

Expected: a panel titled "Super Cool Ad Inserter" appears, containing the descriptive paragraph (matching the classic metabox text, with the site's `scaip_settings_*` values interpolated) and the "Prevent automatic addition of ads to this post." checkbox.

- [ ] **Step 2: Block editor — toggle persists**

1. Check the box. Save the post.
2. Reload.

Expected: checkbox is still checked. Confirm via WP-CLI:

```
n wp post meta get <POST_ID> scaip_prevent_shortcode_addition
```

Expected output: `1` (or `true`).

3. Uncheck. Save. Reload.

Expected: checkbox is unchecked. WP-CLI returns empty.

- [ ] **Step 3: Block editor — front-end behavior**

1. With the checkbox checked, view the front-end of the post.

Expected: no auto-inserted SCAIP ads.

2. Uncheck and save. View front-end.

Expected: ads auto-inserted as normal.

- [ ] **Step 4: Block editor — no classic metabox**

In the block editor for a post, open the "Options" menu → "Preferences" → "Panels" (or the legacy meta-box area at the bottom).

Expected: no `scaip_docs_and_options` classic metabox appears anywhere on the screen.

- [ ] **Step 5: Block editor — RTC detection (when WP 7.0 is available)**

In the block editor for a post, open the browser console and run:

```js
wp.data.select( 'core/editor' ).isCollaborationEnabledForCurrentPost()
```

Expected: `true` (assuming RTC is enabled in Settings → Writing and other RTC-disabling plugins are deactivated for this test).

If running with a real second user, both should be able to edit simultaneously and neither should see the post-locked modal.

- [ ] **Step 6: Classic editor — metabox still works**

1. Activate the Classic Editor plugin (or use a site that has it enabled).
2. Edit a post.

Expected: the existing `scaip_docs_and_options` metabox renders with description + checkbox, identical to today's behavior. Toggling and saving updates the meta as before (verify via `n wp post meta get`).

- [ ] **Step 7: User without `edit_others_posts`**

1. Create or use an Author-role user.
2. Open the block editor for one of their own posts.

Expected: no SCAIP sidebar panel appears. Confirm via DevTools Network tab that `panel.js` was never requested.

- [ ] **Step 8: Other post types — no panel**

1. Open a Page in the block editor as an Editor.

Expected: no SCAIP sidebar panel appears.

- [ ] **Step 9: Existing data compatibility**

1. Identify a post where `scaip_prevent_shortcode_addition` was set under the old code path (value `1` integer).
2. Open it in the block editor.

Expected: the new panel checkbox renders checked. Front-end still skips ad insertion.

---

## Self-Review Notes

**Spec coverage check:**

| Spec section | Implementation task |
|---|---|
| §1 Post meta registration | Task 2 |
| §2 Conditional classic metabox registration | Task 3 |
| §3 Block-editor sidebar panel | Task 4 |
| §4 Editor asset enqueue | Task 5 |
| §5 Save handler stays untouched | (No task — confirmed not modified.) |
| Authorization (3 enforcement points) | Tasks 2 (auth_callback), 3 (cap check), 5 (enqueue gate) |
| Testing plan rows | Task 6 covers all rows; auth_callback row covered by Task 1 |

No gaps.

**Type / signature consistency:**

- `scaip_prevent_shortcode_addition_auth_callback( $allowed, $meta_key, $object_id, $user_id )` — declared in Task 2, called in Task 1's tests. Signatures match.
- Function names `scaip_register_classic_metabox`, `scaip_enqueue_document_panel_assets`, `scaip_prevent_shortcode_addition_auth_callback` are consistent across tasks.
- `window.scaipDocumentPanel` keys (`start`, `period`, `repetitions`, `minimum_paragraphs`) match between Task 4 (JS consumer) and Task 5 (PHP `wp_localize_script`).
- Meta key `scaip_prevent_shortcode_addition` is consistent across PHP and JS.

No drift.
