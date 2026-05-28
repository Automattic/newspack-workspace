# AI agent instructions for newspack-sponsors

See the workspace root `../../AGENTS.md` for Docker setup, the `n` script, coding standards, git rules, and cross-repo workflow. This file covers only what is specific to newspack-sponsors.

## Core architecture

Sponsors are a custom post type (CPT). Each published sponsor post automatically gets a **shadow taxonomy term** in a private taxonomy. Posts/archives are linked to sponsors via that taxonomy term (direct assignment) or via shared categories/tags (the sponsor post is tagged with the same categories/tags as the content it sponsors).

- CPT slug: `Core::NEWSPACK_SPONSORS_CPT = 'newspack_spnsrs_cpt'` (abbreviated, not `newspack_sponsors_cpt`)
- Shadow taxonomy slug: `Core::NEWSPACK_SPONSORS_TAX = 'newspack_spnsrs_tax'`
- Shadow terms are auto-managed by `wp_insert_post` / `before_delete_post` hooks - never create or delete them manually.
- Theme code calls `get_sponsors_for_post()` / `get_sponsors_for_archive()` / `get_all_sponsors()` from `includes/theme-helpers.php`.

## Linting

```bash
npm run lint          # SCSS + JS
npm run lint:php      # PHPCS
npm run fix:php       # PHPCBF auto-fix
```

PHPCS standards: `WordPress-Extra`, `WordPress-Docs`, `WordPress-VIP-Go`. Short array syntax and non-Yoda conditions are allowed (explicitly excluded in `phpcs.xml`). PHP compatibility is checked against `7.2+`.

## Technical details

### Meta fields

All sponsor-level meta is registered on the `newspack_spnsrs_cpt` post type unless noted. To discover the full list:

```bash
grep -n 'register_meta' repos/newspack-sponsors/includes/class-core.php
```

Display-control meta fields (`newspack_sponsor_sponsorship_scope`, `newspack_sponsor_native_byline_display`, `newspack_sponsor_native_category_display`, `newspack_sponsor_underwriter_style`, `newspack_sponsor_underwriter_placement`) have **no `object_subtype`**. They are registered for all post types so that non-CPT posts can hold per-post display overrides.

The sponsor logo is the WordPress **featured image** (`get_post_thumbnail_id()`), not a separate meta field.

**Pattern for adding a new meta field to the sponsor CPT:**

```php
// In Core::register_meta() in includes/class-core.php:
register_meta(
    'post',
    'newspack_sponsor_my_field',
    [
        'object_subtype'    => self::NEWSPACK_SPONSORS_CPT,
        'description'       => __( 'Description of my field.', 'newspack-sponsors' ),
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'single'            => true,
        'show_in_rest'      => true,
        'auth_callback'     => function() {
            return current_user_can( 'edit_posts' );
        },
    ]
);
```

### Settings (wp_options)

Site-wide defaults in `Settings::get_settings()`:
- `newspack_sponsors_default_byline`
- `newspack_sponsors_default_flag`
- `newspack_sponsors_default_disclaimer`
- `newspack_sponsors_suppress_ads`

### JavaScript

The editor build has a single entry point (`src/editor/`) output to `dist/editor.js`.

**The sidebar uses the legacy HOC pattern** (`withSelect` / `withDispatch`), not hooks. Match this style when adding controls:

```js
// Reading meta (src/editor/sidebar/index.js:192-197):
const mapStateToProps = select => {
    const { getEditedPostAttribute } = select( 'core/editor' );
    return {
        meta: getEditedPostAttribute( 'meta' ),
        title: getEditedPostAttribute( 'title' ),
    };
};

// Writing meta (src/editor/sidebar/index.js:200-205):
const mapDispatchToProps = dispatch => {
    const { editPost } = dispatch( 'core/editor' );
    return {
        updateMetaValue: ( key, value ) => editPost( { meta: { [ key ]: value } } ),
    };
};
```

PHP passes data to JS via `wp_localize_script` as `window.newspack_sponsors_data`:
- `post_type` - current post type
- `cpt` - `newspack_spnsrs_cpt`
- `tax` - `newspack_spnsrs_tax`
- `settings` - current site-wide settings
- `defaults` - default values

The editor sidebar (`PluginDocumentSettingPanel`) only renders on sponsor CPT posts (`cpt === postType`). For other post types, the sidebar is injected **into** the taxonomy panels via `addFilter('editor.PostTaxonomyType', ...)`.

## Recipes

### Add a new meta field (PHP + JS)

1. Add a `register_meta()` call in `Core::register_meta()` (`includes/class-core.php`, see pattern above).
2. Destructure the new key from `meta` in `SidebarComponent` (`src/editor/sidebar/index.js:17-27`).
3. Add a control in the JSX return, calling `updateMetaValue('newspack_sponsor_my_field', value)` on change.
4. Build: `n build newspack-sponsors`.

### Support a new post type for sponsorship

1. Use the `newspack_sponsors_post_types` filter in your plugin or theme:

```php
add_filter( 'newspack_sponsors_post_types', function( $post_types ) {
    $post_types[] = 'my_post_type';
    return $post_types;
} );
```

This single filter covers CPT registration, taxonomy registration, editor asset enqueueing, and theme helper functions.

### Add a site-wide setting

1. Add a key/default to `Settings::get_default_settings()`.
2. Add a `get_option()` call in `Settings::get_settings()`.
3. Add an entry to `Settings::get_settings_list()` (supports `input`, `textarea`, `checkbox` types).
4. If the setting should be available in the editor, it will be included automatically via `wp_localize_script` in `Editor::enqueue_block_editor_assets()`.

## Hooks and extension points

```bash
# Find all filters/actions this plugin exposes:
grep -n 'apply_filters\|do_action' repos/newspack-sponsors/includes/
```

Key filter: **`newspack_sponsors_post_types`** - controls which post types can be sponsored, appear in the editor sidebar, and are queried by theme helpers. Default: `['post', 'page']`.

## Gotchas

- CPT is `newspack_spnsrs_cpt` and taxonomy is `newspack_spnsrs_tax` - both are abbreviated. Using the full word `sponsors` will not match.
- Sponsor logo = WordPress featured image. There is no image picker in the JS sidebar; logo is set via the native Featured Image panel.
- Shadow taxonomy terms are auto-managed. Do not call `wp_insert_term` / `wp_delete_term` on `newspack_spnsrs_tax` directly.
- Queries for sponsor posts must include `'is_sponsors' => 1` to prevent other plugins' `pre_get_posts` filters from contaminating results. See `Core::ensure_only_sponsors()`.
- `Settings` has no singleton and is admin-only. Do not call `Settings::get_settings()` in frontend code expecting an instance - it is a static method and works fine, but `Settings::init()` (hooks) is only called inside `is_admin()`.
- The `newspack_sponsor_sponsorship_scope` and per-post display overrides meta fields have no `object_subtype`. They are intentionally registered globally to allow both sponsor posts and regular posts to hold these values.
- JS uses `withSelect`/`withDispatch` HOC pattern, not `useSelect`/`useDispatch` hooks. Match the existing pattern for consistency.
- There are no PHP unit tests in this repo (`phpunit.xml` is absent). No JS unit tests either (`npm run test` is a no-op).
