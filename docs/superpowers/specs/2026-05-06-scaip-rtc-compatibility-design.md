# SCAIP: WordPress 7.0 Real-Time Collaboration Compatibility

**Date:** 2026-05-06
**Repository:** `super-cool-ad-inserter-plugin`
**Status:** Implemented (working tree, no commits) — design simplified during verification (see Design history)

## Background

WordPress 7.0 ships real-time collaborative editing in the block editor. When the editor detects a classic meta box on a post, it disables RTC and falls back to standard post locking. Detection is signaled to a second user via the post-locked modal:

> "This post is already being edited. Because this post uses plugins that aren't compatible with real-time collaboration, only one person can edit at a time."

SCAIP currently registers the `scaip_docs_and_options` classic meta box on the `post` post type. Any block-editor session for a post will therefore disable RTC.

## Goal

Make SCAIP RTC-compatible on the `post` post type. Provide a per-post "prevent automatic ad addition" toggle in the block editor.

## Non-goals

- Expanding SCAIP to post types other than `post`. Auto-insertion is hardcoded to `post` in `inc/scaip-shortcode-inserter.php:186` and stays that way.
- Modifying the `scaip-sidebar` content block (the in-content ad-position block). Unrelated.
- Migrating to a JS build pipeline. The plugin's `package.json` declares `"build": "echo 'No build in this repository.'"`; we keep that posture.
- Fixing the pre-existing classic editor save bug (see Design history). It will be tracked separately.

## Approach

Drop the classic meta box entirely. Add a `PluginDocumentSettingPanel` for the block editor that toggles the same `scaip_prevent_shortcode_addition` post meta. Migrate the meta to `register_post_meta()` with `show_in_rest => true` so the panel can bind to it via `useEntityProp`, which routes through the entity store that RTC syncs.

Removing the classic `add_meta_box()` call entirely is what removes SCAIP from the RTC-disabling list — there is no longer any classic meta box for the editor to detect.

## Components

### 1. Post meta registration

In `inc/scaip-metaboxes.php`, replace the existing `register_meta()` call with:

```php
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

Storing as boolean is safe because the only consumer (`inc/scaip-shortcode-inserter.php:202`) already truthy-checks: `if ( ! empty( $skip ) || 'on' === $skip )`. The custom `scaip_prevent_shortcode_addition_sanitize` callback can be removed; boolean handling covers it.

### 2. Block-editor sidebar panel

New file: `assets/js/scaip-document-panel.js`. Vanilla `wp.element.createElement`, mirroring the style of `blocks/scaip-sidebar/block.js`. Registered via `wp.plugins.registerPlugin` and rendered as a `PluginDocumentSettingPanel`.

Panel content:
- A descriptive paragraph, built with `sprintf` and the localized `scaip_settings_*` options.
- The "Prevent automatic addition of ads to this post." checkbox (`CheckboxControl`), bound to `scaip_prevent_shortcode_addition` via `useEntityProp( 'postType', 'post', 'meta' )`.

The panel renders `null` when the current post type isn't `post`. Both `useSelect` and `useEntityProp` are called unconditionally before the early return to comply with React's Rules of Hooks. The setter passes a partial-update object (`{ scaip_prevent_shortcode_addition: value }`); `useEntityProp`'s setter merges into the meta record internally.

### 3. Editor asset enqueue

PHP function hooked on `enqueue_block_editor_assets`. Gates:
- Bail unless `get_current_screen()->is_block_editor()` is true and `$screen->id === 'post'`.
- Bail if the user lacks `edit_others_posts` (so the panel JS isn't even shipped to users who can't toggle the meta).

Localizes `scaipDocumentPanel` with the same four `scaip_settings_*` options that the panel reads.

### 4. Removed code

The following classic-editor code is removed entirely:

- `scaip_how_to_shortcode_callback()` — classic metabox content callback.
- The classic metabox registration via `add_meta_box()` and its `add_meta_boxes` action hook.
- `_scaip_meta_box_save()` — save handler, and its `save_post` action hook.

## Authorization

The `edit_others_posts` capability is enforced in two places:

1. Server-side enqueue gate — users without the cap don't receive the panel JS.
2. `register_post_meta` `auth_callback` — REST writes are rejected at the API boundary.

## Data flow

1. User opens a `post` in block editor.
2. PHP enqueues `scaip-document-panel` script with localized settings.
3. Panel reads meta via `useEntityProp` (sync-safe path).
4. Toggle change calls the setter; the meta change goes into the entity record edit buffer.
5. On post save, the REST request includes the meta update; `auth_callback` gates the write; meta persists.
6. RTC remains enabled because no `add_meta_box` is registered for this screen.

## Backward compatibility

- Existing `scaip_prevent_shortcode_addition` meta values (saved as integer `1`) keep working. The consumer truthy-checks; boolean `true` and integer `1` both satisfy `! empty()`.
- Classic-editor users lose the visible UI control, but the meta value (if previously set) is still respected by the front-end. In practice no classic-editor user has been able to set this via UI for some time anyway — see Design history.
- The legacy `'on'` string check at `scaip-shortcode-inserter.php:202` stays — older saved values from the pre-refactor era keep working.

## Testing plan

| Scenario | Expected |
|---|---|
| Block-editor `post`, user with `edit_others_posts` | Sidebar panel visible with description + checkbox; checkbox state persists across save/reload. |
| Block-editor `post`, user without `edit_others_posts` | Panel JS not enqueued; no sidebar panel. |
| Block-editor `post`, RTC-enabled WP 7.0 | `wp.data.select('core/editor').isCollaborationEnabledForCurrentPost()` returns `true`. Two users editing simultaneously do not see the post-locked modal. |
| Block-editor `page` or other post type | Nothing loads. |
| Existing post with stored `scaip_prevent_shortcode_addition = 1` | Panel checkbox renders checked; ad insertion still skipped on front-end. |

## Files touched

- `inc/scaip-metaboxes.php` — replaced `register_meta()` with `register_post_meta()`; added auth callback; added enqueue function; removed classic metabox callback, screen-checked registration, and save handler.
- `assets/js/scaip-document-panel.js` — new file, sidebar panel implementation.
- `tests/inc/test-scaip-metaboxes.php` — new file, two tests covering the auth callback (Author denied, Editor allowed).
- `super-cool-ad-inserter-plugin.php` — no change.

## Risk

Low. The change is additive for block-editor users. Classic-editor users lose UI access to a toggle that was already non-functional (see Design history). The boolean meta type is the one substantive data-model change; the consumer's truthy check makes that safe.

## Design history

The original design (option 2 in the brainstorming session) preserved the classic metabox conditionally — registering it only on non-block-editor screens — to keep classic-editor users supported.

During the manual verification step, the classic editor save behavior was tested and found to be broken: the checkbox state did not persist after save. This was reproduced on `trunk` (without our changes) and on a fresh JN site with only SCAIP and the Classic Editor plugin active. The save handler was therefore non-functional for some unknown duration before this work.

Given that the classic UI was effectively dead code, the design was simplified to drop the classic metabox entirely. This eliminates ~90 lines of code, removes a misleading non-functional UI, and produces a cleaner block-editor-only design.

The pre-existing classic editor save bug remains unfixed in this work and will be tracked separately.
