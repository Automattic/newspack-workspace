/**
 * Unit-local window globals for the Subscribe block.
 *
 * Global script (no top-level imports; inline import() types only).
 * `newspack_newsletters_blocks` is localized for the block editor and
 * `newspack_newsletters_subscribe_block` for the reader-facing view script;
 * both come from the Subscribe block's PHP render/enqueue.
 */

/** An access gate restricting a subscription list. */
interface ListGate {
	/** Gate title shown to editors. */
	title: string;
	[ key: string ]: unknown;
}

/** Editor-side localized data for the Subscribe block. */
interface NewspackNewslettersBlocksData {
	/** Admin URL to the subscription lists settings screen. */
	settings_url: string;
	/** Active ESP slug (e.g. 'mailchimp'). */
	provider: string;
	supports_recaptcha: boolean;
	has_recaptcha: boolean;
	recaptcha_url: string;
	/** Access gates keyed by list ID. A list with no gate is absent. */
	list_gates?: Record< string, ListGate[] >;
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

/**
 * The Reader Activation (Audience Management) runtime exposed on
 * `window.newspackReaderActivation` and handed to callbacks queued on
 * `window.newspackRAS`. Methods are optional because the view script runs
 * against older newspack-plugin builds that may not expose all of them.
 */
interface ReaderActivation {
	dispatchActivity: ( action: string, data: Record< string, unknown > ) => void;
	openVerificationModal?: ( options: {
		email?: string;
		verificationNonce?: string;
		onSendCode?: () => void;
	} ) => void;
	openAuthModal?: ( options: Record< string, unknown > ) => void;
	[ key: string ]: unknown;
}

interface Window {
	/** Queue of callbacks invoked with the Reader Activation runtime once ready. */
	newspackRAS?: Array< ( ras: ReaderActivation ) => void >;
}
