# Email Renderers

The **WooCommerce (WC) rendering engine** for Newspack newsletters — a second renderer that turns a newsletter's Gutenberg blocks into email-safe HTML, running alongside the legacy MJML renderer and selected per-site by a feature flag.

It's built on the [WooCommerce Email Editor package](https://github.com/woocommerce/woocommerce/tree/trunk/packages/php/email-editor), so newsletters render through real WordPress block output instead of a bespoke MJML template. The legacy `Newspack_Newsletters_Renderer` stays in place; the flag decides which engine a site uses at runtime, so the WC engine can roll out gradually and roll back instantly.

Everything here is in the `Newspack\Newsletters\Email_Renderers` namespace, autoloaded via the Composer classmap on `includes/`. The `blocks/` override files are classmapped too, but the registry also loads them eagerly so they self-register without anything referencing them by name — see [Adding an override](#adding-an-override).

### Package version

This code targets `woocommerce/email-editor` **`2.14.0`** (pinned in `composer.json`). It depends on the exact rendered markup and renderer APIs of that release — `Full_Bleed_Sections` and the `blocks/` overrides in particular parse the package's canvas output. On a WooCommerce site the same package is also shipped by Woo core through its own jetpack-autoloader, which loads the single highest version site-wide, so the actually-loaded classes may be **newer** than the pin. The render path degrades gracefully (structure mismatch → no-op band, bad override → fail closed), so a drift produces plain-but-correct email rather than a fatal — but a future Woo bump that reshapes the canvas markup can silently disable full-bleed banding or an override. When bumping the pin (or when Woo core moves ahead of it), smoke-test full-bleed sections and the overridden blocks against the loaded version.

## Reference model: vanilla WordPress, not MJML

When deciding what "correct" output is for a block under the WC engine, **compare against vanilla WordPress block output — not the legacy MJML rendering.** Many MJML choices were workarounds for MJML's own constraints, not deliberate design. Treat MJML divergences as suspect, and only add an override (below) when the package output genuinely diverges from WP.

The audit and per-block tracking live in Linear: **NEWS-1901** (audit), **NEWS-1904** (per-block).

## Architecture & data flow

The flag selects the engine. The **editor refresh** renders through it and saves the result into the `newspack_email_html` meta — that saved HTML is what gets sent.

```
                  Feature_Flag::is_enabled()
                            │
      editor refresh (src/editor/mjml) branches on the flag
              ┌─────────────┴─────────────┐
           flag ON                      flag OFF
              │                            │
   post-html → render_wc        post-mjml → client MJML compile
              │                            │
              └──────► newspack_email_html ◄┘   (saved by the editor)
                            │
              ESP send reads it (retrieve_email_html)
```

- **`active_engine()`** reads the flag and returns `'wc'` or `'mjml'`. The editor refresh (`refreshEmailHtml`) branches on it: WC → the `post-html` REST endpoint → `Renderer_Controller::render_wc()`; MJML → the `post-mjml` endpoint + a client-side compile. The resulting HTML is saved to the `newspack_email_html` meta.
- **`render_wc()`** is the WC render entry, called by the `post-html` round-trip. It renders the **saved** post (the WC engine re-fetches by ID, so unsaved content is ignored).
- **At send**, the ESP provider **reads** the saved `newspack_email_html` (`Newspack_Newsletters_Renderer::retrieve_email_html`) — it doesn't re-render. `send_newsletter()` additionally **stamps** the producing engine on the post (it does not write the HTML). A sent newsletter never re-renders, so the stamp survives a later flag flip.
- The boundary is narrow: everything downstream (ESPs, tracking, layouts) just reads `newspack_email_html` and doesn't care which engine produced it.

## Components

### `Feature_Flag` — `class-feature-flag.php`
Whether the WC renderer is on. **Off by default.** Precedence (low → high):

1. Option `newspack_newsletters_use_woo_renderer`
2. Constant `NEWSPACK_NEWSLETTERS_WOO_RENDERER`
3. Filter `newspack_newsletters_use_woo_renderer`

```php
add_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' ); // force on
```

### `Renderer_Controller` — `class-renderer-controller.php`
Engine resolution, the WC render entry, and the producing-engine stamp.

- **`active_engine(): string`** — `'wc'` or `'mjml'` from the flag.
- **`render_wc( ?WP_Post ): string`** — renders via the package; returns `''` (never fatals) on bad input / missing package / thrown error.
- **`get_rendering_post(): ?WP_Post`** — the post currently rendering. The theme filter needs it because the package applies theme.json with no post argument and doesn't set the global `$post`.
- **`stamp_renderer()` / `get_post_renderer()`** — write/read the `newspack_newsletter_renderer` meta. Lossy toward MJML: only an exact `'wc'` stamp resolves to `'wc'`; absent/anything else → `'mjml'` (so pre-feature newsletters resolve correctly).

Constants: `ENGINE_WC`, `ENGINE_MJML`, `RENDERER_META`.

### `Editor_Bootstrap` — `class-editor-bootstrap.php`
Boots the package and wires it to the newsletters CPT. `init()` is idempotent and bails when the package is absent. It:

1. Boots the package container (unconditionally — see below).
2. Opts the CPT into the editor **only when `Feature_Flag::is_enabled()`** (via `add_post_type()`), and — again only when the flag is on — re-asserts Newspack's canonical CPT args at `init:11` (`reassert_cpt_when_enabled()`), since the package re-registers opted-in post types with email defaults at `init:10`. With the flag off the package registers nothing at `init:10`, so the re-assertion is skipped rather than run as duplicate work every request.
3. Registers the wrapping block template (`templates/newspack-newsletter.html`).
4. Wires per-newsletter theme.json via `woocommerce_email_editor_theme_json` → `Theme_Json_Builder::build()`.
5. Wires the override registry (`Block_Renderer_Registry::init()`).

**Why the boot is unconditional but the CPT opt-in is flag-gated.** The boot itself runs for both flag states (one boot path; it must run before `init` so the package's own `init` callbacks register in time, and the WC-engine tests call `init()` directly regardless of flag). What is gated is the **CPT opt-in** in `add_post_type()`, because the package's `Email_Editor::initialize()` registers a `single_template` filter (`load_email_preview_template`) that only takes over post types present in `woocommerce_email_editor_post_types`. Left ungated, a *public* newsletter (one set for both email and web) rendered on the **front end** through the package's bare email-preview template — no theme header, footer or sidebar — instead of the theme's standard single template. (Email-only blocks stayed hidden even then: `Newspack_Newsletters::remove_visibility_hidden_block()` is a `render_block` filter that still runs under the package's template, so the regression was the theme wrapper only.) Gating the opt-in keeps a flag-off site on the legacy (MJML) behavior. The editor takeover in `Newspack_Newsletters_Editor` is separately flag-gated; the opt-in gate closes the front-end path it never covered.

The flag is read lazily inside `add_post_type()`, so the option and constant (always set in time) and any `newspack_newsletters_use_woo_renderer` filter registered before `init:10` are honored; a filter added after `init:10` half-engages, because `register_email_post_types()` and `Templates::initialize()` read the list once at that priority and cache it. With the flag off the boot still registers site-wide machinery, but it is either additive or post-type-gated: a `sent` post status, the email-editor preview REST routes, an `email-contents` block-pattern category, a `post_types` field on the `wp_template` REST response, and a `safe_style_css` KSES allow-list widening (`display` + `mso-*`). None of it engages the front-end template path.

**Residual flag-on behavior (not closed here).** This gate fixes the flag-*off* default only. With the flag *on*, the CPT is opted in and nothing removes the package's `single_template` filter, so a public newsletter still renders through the bare email-preview template — and because that template runs no loop, Newspack's `the_post`-hooked content gate never engages, so a web-restricted newsletter's full text is exposed to anonymous readers. This predates the gate and is not addressed here; deciding what a public newsletter should look like under the WC engine is tracked in [NPPD-2150](https://linear.app/a8c/issue/NPPD-2150/render-newsletter-post-in-theme-template).

### `Theme_Json_Builder` — `class-theme-json-builder.php`
Translates a newsletter's configured theme into a theme.json array. **Read-only.** `build( WP_Post ): array` maps:

| Output | From |
| --- | --- |
| `styles.color.*` | `background_color` / `text_color` meta |
| Fonts (body + heading) | `font_header` / `font_body` meta (validated; default Arial / Georgia) |
| `fontSizes` presets | Newspack font-size scale; **fluid typography off** (fixed px in email) |
| `spacingSizes` presets | Newspack spacing scale (`var:preset|spacing|*` resolves) |
| `color.palette` | `NEWSPACK_NEWSLETTERS_PALETTE_META` option — **omitted when empty** (an empty palette would wipe the editor's default presets on merge) |

### `Block_Renderer_Registry` + `blocks/`
The block override system. The package sets each block's `render_email_callback` during the `block_type_metadata_settings` filter (priority 10); this registry hooks the same filter later and re-points that callback for the blocks Newspack overrides. See [below](#adding-an-override).

### Send-time stamp
In `class-newspack-newsletters-service-provider.php`, `send_newsletter()` stamps the engine after a successful send. **Send time is the source of truth** — the stamp records the engine resolved at dispatch.

## Adding an override

Overrides **self-register** — drop a file in `blocks/` and it's picked up; there's no central map to edit. `Block_Renderer_Registry::init()` loads every `blocks/class-*.php` so each registers itself, and instances are created lazily.

**1. Create `blocks/class-<block>.php`** in the `…\Email_Renderers\Blocks` namespace.

**2. Extend the right base:**

- **Overriding a core block** → extend the package's renderer (inherit its behavior, change only what you need):
  ```php
  class Column extends Package_Column { /* … */ }
  ```
- **New Newspack block** → extend `Abstract_Block_Renderer`.

**3. Implement `render_content()`:**
```php
protected function render_content( string $block_content, array $parsed_block, Rendering_Context $rendering_context ): string {
	// Return email-safe HTML for this block.
}
```

**4. Self-register at the bottom of the file:**
```php
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'core/column', Column::class );
```

> **Note:** `blocks/` files are classmapped like everything in `includes/`, but the registry also loads them **eagerly** via `glob()` so their bottom-of-file `add()` runs without anything referencing the class — a manual `require_once` is therefore redundant. The flip side: a file that doesn't match `class-*.php`, or one that omits the `add()` call, is silently skipped rather than failing loudly. If an override "isn't taking", check the filename and that the `add()` line is present.

### Worked example: `core/column` percentage widths

The canonical override (`blocks/class-column.php`). The package strips column width units (`Styles_Helper::parse_value( '70%' )` → `width="70"` = 70px), collapsing multi-column layouts. Vanilla WP keeps the percentage, so we override:

```php
class Column extends Package_Column {
	protected function render_content( string $block_content, array $parsed_block, Rendering_Context $rendering_context ): string {
		return self::preserve_percentage_width(
			parent::render_content( $block_content, $parsed_block, $rendering_context ),
			(string) ( $parsed_block['attrs']['width'] ?? '' )
		);
	}
	// preserve_percentage_width(): pure string helper, unit-tested in isolation.
}
```

The pattern — **delegate to `parent::render_content()`, then post-process to match vanilla WP** — fits most core-block overrides. Extending the package class also inherits important behavior (e.g. `Column`'s no-op `add_spacer()`, which keeps columns side by side). Keep the transform a pure helper so it's testable without booting the package.

## Testing

Tests mirror the source under `tests/email-renderers/`:

| Test | Covers |
| --- | --- |
| `test-feature-flag.php` | Flag precedence |
| `test-renderer-controller.php` | Engine resolution + stamp resolver |
| `test-editor-bootstrap.php` | Template registration + CPT targeting |
| `test-theme-json-builder.php` | Color/font/preset mapping + palette omission |
| `test-block-renderer-overrides.php` | Width helper + override wiring |
| `test-rest-post-html.php` | `post-html` endpoint |
| `test-stamp-on-send.php` | Send-time stamping |

```bash
n test-php                                   # all newsletters PHP tests
n test-php --filter Test_Renderer_Controller # one class
```

When adding an override, add a sibling test asserting the package output is transformed as intended.
