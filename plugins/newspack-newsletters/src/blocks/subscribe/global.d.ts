/**
 * Unit-local window globals for the Subscribe block.
 *
 * Global script (no top-level imports; inline import() types only).
 * `newspack_newsletters_blocks` is localized for the block editor and
 * `newspack_newsletters_subscribe_block` for the reader-facing view script;
 * both come from the Subscribe block's PHP render/enqueue.
 */

/** Editor-side localized data for the Subscribe block. */
interface NewspackNewslettersBlocksData {
	/** Admin URL to the subscription lists settings screen. */
	settings_url: string;
	/** Active ESP slug (e.g. 'mailchimp'). */
	provider: string;
	supports_recaptcha: boolean;
	has_recaptcha: boolean;
	recaptcha_url: string;
	[ key: string ]: unknown;
}

/** Front-end localized data for the Subscribe block view script. */
interface NewspackNewslettersSubscribeBlockData {
	/** Localized "invalid email" error message. */
	invalid_email: string;
	[ key: string ]: unknown;
}

declare const newspack_newsletters_blocks: NewspackNewslettersBlocksData;
declare const newspack_newsletters_subscribe_block: NewspackNewslettersSubscribeBlockData;
