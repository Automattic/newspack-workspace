# Email Renderers

## A second newsletter renderer, built on the WooCommerce email-editor package, that runs alongside the legacy MJML renderer and is selected per-site by a feature flag.

This directory is the **WooCommerce (WC) rendering engine** for Newspack newsletters. It turns a newsletter post — a tree of Gutenberg blocks — into the final, email-safe HTML that gets sent to an ESP (Mailchimp, ActiveCampaign, Constant Contact, …).

Newspack has always shipped an MJML-based renderer (`Newspack_Newsletters_Renderer`, the `*.mjml.php` template, and the React MJML preview). That renderer stays in place. This system is a **parallel engine** built on the [WooCommerce Email Editor package](https://github.com/woocommerce/woocommerce/tree/trunk/packages/php/email-editor) — the same block-based email engine WooCommerce uses — so newsletters render through real WordPress block output instead of a bespoke MJML template. Which engine a given site uses is decided at runtime by a single feature flag, so the WC engine can be rolled out gradually and rolled back instantly.

Everything here lives under the `Newspack\Newsletters\Email_Renderers` namespace and is autoloaded via the Composer classmap on `includes/` (the three entry-point classes — `Feature_Flag`, `Renderer_Controller`, `Editor_Bootstrap` — are also `require_once`'d eagerly from `newspack-newsletters.php`, and `Editor_Bootstrap::init()` is called from there).

## Reference model: vanilla WordPress, not MJML

The target for block-rendering fidelity is **vanilla WordPress block output** — what core's `render_block()` / the front end produces — **not** the legacy MJML rendering.

The legacy MJML output is *not* the source of truth. Many of its choices were workarounds for MJML's own constraints (its column model, spacing primitives, and table scaffolding), not deliberate design decisions. When deciding what "correct" output looks like for a block under the WC engine, compare against vanilla WP, treat MJML divergences as suspect, and only add a Newspack override (see below) when the package's output genuinely diverges from what WP produces.

The per-block fidelity audit and the block-by-block tracking of where the WC engine matches or diverges live in Linear: **NEWS-1901** (audit) and **NEWS-1904** (per-block tracking).

## Architecture & data flow

There are two render paths and one dispatch seam between them.

```
                         Feature_Flag::is_enabled()
                                   │
                 ┌─────────────────┴─────────────────┐
              flag ON                              flag OFF
                 │                                     │
         WC email-editor engine               legacy MJML renderer
   (Renderer_Controller::render_wc)     (Newspack_Newsletters_Renderer)
                 │                                     │
                 └──────────────► newspack_email_html ◄┘
                          (EMAIL_HTML_META, the HTML sent to the ESP)
```

- **`Renderer_Controller` is the dispatch seam.** `active_engine()` reads the flag and returns `'wc'` or `'mjml'`. New newsletters render through whichever engine is active.
- **Editor preview** round-trips through the REST endpoint `newspack-newsletters/v1/post-html` (`Newspack_Newsletters::api_get_post_html`), which calls `Renderer_Controller::render_wc( $post )` and returns `{ html }`. This is read-only: it renders the **saved** post, because the WC engine re-fetches the post from the database by ID at render time (the package's `Post_Content` renderer is stateless), so a live in-editor `content` override would be ignored — unlike the MJML preview endpoint `api_get_mjml`, which accepts unsaved content.
- **Sent newsletters are frozen and stamped.** At send time the service provider writes the final HTML into the `newspack_email_html` meta and stamps the producing engine onto the post (`newspack_newsletter_renderer` meta). A sent newsletter's HTML never re-renders, so the stamp records which engine produced what was actually mailed — surviving any later flag flip.

The engine boundary is deliberately narrow: the rest of the plugin (ESP providers, tracking, layouts) reads the rendered HTML out of `newspack_email_html` and does not care which engine produced it.

## Components

### `Feature_Flag` — `class-feature-flag.php`

Resolves whether the WC renderer is enabled for this site. **Off by default.** Three layers, in increasing precedence:

1. Option `newspack_newsletters_use_woo_renderer` (default `false`).
2. Constant `NEWSPACK_NEWSLETTERS_WOO_RENDERER` — overrides the option when defined.
3. Filter `newspack_newsletters_use_woo_renderer` — applied last, wins over both.

```php
if ( \Newspack\Newsletters\Email_Renderers\Feature_Flag::is_enabled() ) {
	// WC engine is active for this site.
}
```

```php
// Force the WC engine on, e.g. in wp-config.php:
define( 'NEWSPACK_NEWSLETTERS_WOO_RENDERER', true );

// Or per-request via the filter:
add_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );
```

### `Renderer_Controller` — `class-renderer-controller.php`

The dispatch point and the WC render entry point.

- **`active_engine(): string`** — `'wc'` when the flag is on, else `'mjml'`. The source of truth for "what should render new newsletters right now."
- **`render_wc( ?WP_Post $post ): string`** — renders a newsletter through the package renderer and returns email HTML. Defensive: returns `''` (never fatals) when the post is invalid, the email-editor package is unavailable, or the renderer throws (it logs via `Newspack_Newsletters_Logger`). Internally it sets the post on a static accessor before delegating, so the theme.json filter can resolve per-newsletter colors (see below), and restores the previous post in a `finally` block (save/restore, not clear, so a nested render leaves the outer render's context intact).
- **`get_rendering_post(): ?WP_Post`** — returns the post `render_wc()` is currently rendering, or `null` when idle. Exists because the package applies its theme.json filter with **no** post argument and `Renderer::render()` does not set up the global `$post`, so a filter relying on `get_post()` would get `null` during a REST round-trip. This static carries the post explicitly.
- **`stamp_renderer( $post_id, $engine )` / `get_post_renderer( $post_id ): string`** — write/read the producing-engine post-meta `newspack_newsletter_renderer`. The write is intentionally lossy toward MJML: `get_post_renderer()` returns `'wc'` **only** when the stamp is exactly `'wc'`; **an absent (or any other) stamp resolves to `'mjml'`**, so newsletters that predate this feature are correctly treated as MJML.

Constants: `ENGINE_WC = 'wc'`, `ENGINE_MJML = 'mjml'`, `RENDERER_META = 'newspack_newsletter_renderer'`.

### `Editor_Bootstrap` — `class-editor-bootstrap.php`

Boots the WooCommerce email-editor package and wires it to the newsletters CPT. `init()` is idempotent (guarded by a static flag) and bails when the package classes are absent. It:

1. **Boots the package container** — `Email_Editor_Container::container()->get( Bootstrap::class )->init()`.
2. **Opts the newsletters CPT into the editor** via the `woocommerce_email_editor_post_types` filter. Because the package re-registers opted-in post types on `init:10`, `init()` also re-asserts Newspack's canonical CPT definition at `init:11` (`Newspack_Newsletters::register_cpt`) so the package's email defaults don't clobber the CPT's public/labels/rewrite/menu/rendering-mode args.
3. **Registers the wrapping block template** (`woocommerce_email_editor_register_templates`) from `templates/newspack-newsletter.html`. The template id is composed by the package as `newspack//newspack-newsletter` (`TEMPLATE_NAMESPACE` + `TEMPLATE_SLUG`), and `render_wc()` passes that slug to the renderer.
4. **Wires per-newsletter theme.json** via the `woocommerce_email_editor_theme_json` filter → `merge_theme_json()`. That callback resolves the render post (from `Renderer_Controller::get_rendering_post()`, falling back to `get_post()`), builds a `WP_Theme_JSON` from `Theme_Json_Builder::build()`, memoizes it per post ID (the filter fires several times per render), and merges it into the editor theme.
5. **Wires the block-renderer override registry** — `Block_Renderer_Registry::init()`.

### `Theme_Json_Builder` — `class-theme-json-builder.php`

Translates a newsletter's configured theme into the theme.json array the WC renderer consumes. **Read-only**: it reads post meta and options, never writes them. `build( WP_Post $post ): array` produces:

- **Colors** — `styles.color.background` / `styles.color.text` from the `background_color` / `text_color` meta (sanitized, defaulting to `#ffffff` / `#000000`).
- **Fonts** — `font_header` / `font_body` meta, validated against `Newspack_Newsletters::$supported_fonts`, falling back to `Arial, Helvetica, sans-serif` (headings) and `Georgia, serif` (body).
- **Font sizes** — Newspack's newsletter font-size scale (`xx-small` … `xxxxxx-large`) as theme.json presets, mirroring `Newspack_Newsletters_Renderer::get_font_size()` so presets resolve to the same pixel values the editor has always used. **Fluid typography is disabled** (`settings.typography.fluid = false`) so sizes resolve to fixed pixels in email.
- **Spacing** — Newspack's spacing scale (`20`…`80`) as `spacingSizes` presets, so `var:preset|spacing|*` references resolve.
- **Palette** — built from the `NEWSPACK_NEWSLETTERS_PALETTE_META` option, sanitized per entry. **Omitted entirely when unconfigured** — `WP_Theme_JSON::merge()` replaces preset arrays per origin, so emitting an empty palette would wipe the editor's default color presets rather than leave them intact.

### `Block_Renderer_Registry` + `blocks/` — the block override system

The package assigns each core block a `render_email_callback` during the `block_type_metadata_settings` filter (priority 10). This registry hooks the **same filter at a later priority** and swaps that callback for the blocks Newspack overrides, leaving every other block untouched. `init()` guards on the presence of the package's renderer classes so the override only wires up when the email-editor package is loaded. Renderer instances are lazily instantiated on first use. See the next section for how to add one.

### Send-time stamp / resolver

In `class-newspack-newsletters-service-provider.php`, `send_newsletter()` — after a successful `send()` — calls:

```php
Renderer_Controller::stamp_renderer(
	$post_id,
	Renderer_Controller::active_engine()
);
```

**Send time is the source of truth.** The dispatched HTML reflects the engine resolved at send (and for a scheduled send the flag is read on dispatch, not at authoring), so a flag flip between authoring and send is recorded as the send-time engine. The write is intentionally lossy toward MJML: if the meta write fails, `get_post_renderer()`'s default reports `'mjml'`.

## Block rendering & overrides

This is the most important section for day-to-day work on rendering fidelity.

### How blocks render through the package

The WC email-editor package renders a newsletter by walking its block tree. For each block type, the package looks up a **`render_email_callback`** — set per block during the `block_type_metadata_settings` filter — and calls it with the block content, the parsed block, and a `Rendering_Context`. Core blocks (columns, buttons, image, paragraph, …) ship with package renderers under `Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks`.

Newspack overrides individual block renderers by **re-pointing that callback** at a Newspack renderer for just the blocks we need to fix — every other block keeps the package's renderer. See the [reference model](#reference-model-vanilla-wordpress-not-mjml): only override when the package output diverges from vanilla WordPress.

### Adding a Newspack override (self-registration)

> **Note:** Newspack overrides self-register. Each renderer file registers itself with `Block_Renderer_Registry::add()`, and `Block_Renderer_Registry::init()` discovers and loads every file in `blocks/` so they register. There is no central hardcoded map to edit — drop in a file and it's picked up.

**1. Create the renderer file** in `blocks/`, in the `Newspack\Newsletters\Email_Renderers\Blocks` namespace:

`includes/email-renderers/blocks/class-<block>.php`

**2. Pick a base class:**

- **Overriding a core block?** Extend the package's renderer for that block, so you inherit its behavior and only change what you need:

  ```php
  use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Column as Package_Column;

  class Column extends Package_Column { /* … */ }
  ```

  Extending the package class matters beyond convenience: e.g. the package's `Column` ships a no-op `add_spacer()` (columns render side by side and must **not** be spacer-wrapped), and you inherit that by subclassing rather than reimplementing it.

- **Brand-new Newspack block?** Extend the package's abstract base and implement the render hook:

  ```php
  use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Abstract_Block_Renderer;

  class My_Block extends Abstract_Block_Renderer { /* … */ }
  ```

**3. Implement `render_content()`** — the protected hook the package calls:

```php
protected function render_content(
	string $block_content,
	array $parsed_block,
	Rendering_Context $rendering_context
): string {
	// Return the email-safe HTML for this block.
}
```

**4. Self-register at the bottom of the file:**

```php
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add(
	'core/column',
	Column::class
);
```

`Block_Renderer_Registry::init()` globs the `blocks/` directory and loads each file so its `add()` call runs; the registry lazily instantiates each renderer the first time the package asks for that block's callback.

### Worked example: `core/column` percentage widths

`blocks/class-column.php` is the canonical override. The problem: the package's column wrapper sets the cell width via `Styles_Helper::parse_value( $width )`, whose regex grabs the leading number and **drops the unit** — so a `70%` column renders `width="70"` (= 70px) and the multi-column layout collapses. Vanilla WP keeps the percentage; the package doesn't. So we override.

The fix delegates to the package for the markup, then restores the percent on the wrapper cell:

```php
class Column extends Package_Column {
	protected function render_content( string $block_content, array $parsed_block, Rendering_Context $rendering_context ): string {
		return self::preserve_percentage_width(
			parent::render_content( $block_content, $parsed_block, $rendering_context ),
			(string) ( $parsed_block['attrs']['width'] ?? '' )
		);
	}
	// preserve_percentage_width() — a pure string transform, unit-tested in isolation —
	// rewrites the first wrapper <td>'s numeric width back to a percentage.
}
```

```php
// Bottom of the file:
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'core/column', Column::class );
```

The width-restoration logic is split into a pure static helper (`preserve_percentage_width()`) precisely so it can be unit-tested without standing up the package renderer. The pattern — *delegate to `parent::render_content()`, then post-process the package's HTML to match vanilla WP* — is the model for most core-block overrides.

## Testing

PHPUnit tests mirror the source tree under `tests/email-renderers/`:

| Test file | Covers |
| --- | --- |
| `test-feature-flag.php` | Flag default, option, constant, and filter precedence |
| `test-renderer-controller.php` | Engine resolution and the stamp resolver (absent stamp → mjml) |
| `test-editor-bootstrap.php` | Wrapping-template registration and CPT targeting |
| `test-theme-json-builder.php` | Color/font/preset mapping and palette omission |
| `test-block-renderer-overrides.php` | The column width helper and override wiring |
| `test-rest-post-html.php` | The `post-html` REST endpoint (render, 404, 500) |
| `test-stamp-on-send.php` | Send-time stamping per engine |

Run them from the plugin directory:

```bash
n test-php                                   # all newsletters PHP tests
n test-php --filter Test_Renderer_Controller # one test class
```

When you add a block override, add a sibling test that asserts the package output is transformed as intended — and, where practical, keep the transform a pure helper so it can be tested without booting the package.
