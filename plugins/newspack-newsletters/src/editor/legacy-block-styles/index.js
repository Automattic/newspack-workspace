/**
 * Entry whose only job is to emit `legacyBlockStyles.css`.
 *
 * These are the legacy MJML-era block-appearance overrides, split out of the
 * editor's main `style.scss` so PHP can skip them when the WooCommerce email
 * renderer is active (see `Newspack_Newsletters_Editor::enqueue_block_assets`).
 * The emitted JS bundle is intentionally unused.
 */
import './style.scss';
