/**
 * Ambient declarations for browser globals consumed by the reader-activation
 * family of entries (src/reader-activation, src/reader-activation-auth,
 * src/reader-activation-newsletters).
 *
 * Global script file — no top-level imports; use inline import() types only.
 * The cross-plugin reader-activation contract itself (NewspackReaderActivation,
 * newspack_ras_config, window.newspackRAS) is declared canonically in
 * newspack-scripts/types/newspack-globals.d.ts, included via tsconfig.
 */

/**
 * Reader data bootstrap, localized as `newspack_reader_data` by
 * Reader_Data::enqueue_scripts(). Also used as shared mutable state between
 * the separately-bundled entries (session nonce, server items cache), so
 * consumers seed `window.newspack_reader_data = window.newspack_reader_data || {}`.
 */
interface NewspackReaderData {
	store_prefix?: string;
	/** Whether this is a temporary (sessionStorage-backed, non-synced) session. */
	is_temporary?: boolean;
	api_url?: string;
	/** REST nonce cached by session hydration (src/reader-activation/session). */
	nonce?: string;
	session_url?: string;
	/** Server-known store items, keyed by store key; values are JSON-encoded strings. */
	items?: Record< string, unknown >;
	read_only_keys?: string[];
	/** Server-dispatched activities to push on init. */
	reader_activity?: Array< { action: string; data: Record< string, unknown > } >;
	[ key: string ]: unknown;
}

declare const newspack_reader_data: NewspackReaderData;

/**
 * Labels localized as `newspack_reader_activation_labels` on the auth script
 * handle by Reader_Activation::get_reader_activation_labels().
 */
interface NewspackReaderActivationLabels {
	signin: {
		title: string;
		success_title: string;
		success_description: string;
		[ key: string ]: unknown;
	};
	register: {
		title: string;
		success_title: string;
		success_description: string;
		[ key: string ]: unknown;
	};
	invalid_email: string;
	invalid_password: string;
	blocked_popup: string;
	code_sent: string;
	code_resent: string;
	verification_error: string;
	sign_in_to_upgrade: string;
	register_to_upgrade: string;
	[ key: string ]: unknown;
}

declare const newspack_reader_activation_labels: NewspackReaderActivationLabels;

/**
 * The Google reCAPTCHA API (v2 and v3 surfaces used by the frontend
 * registration flow in src/reader-activation/index).
 */
interface NewspackGoogleRecaptchaApi {
	ready( callback: () => void ): void;
	/** v3: execute a site-key action and resolve with a token. */
	execute( siteKey: string, options: { action: string } ): Promise< string >;
	/** v2: execute a rendered (invisible) widget. */
	execute( widgetId: number ): void;
	render(
		container: HTMLElement,
		parameters: {
			sitekey: string;
			size?: string;
			isolated?: boolean;
			callback?: ( token: string ) => void;
			'error-callback'?: () => void;
			'expired-callback'?: () => void;
		}
	): number;
}

/**
 * Newspack's reCAPTCHA integration client, exposed by
 * src/other-scripts/recaptcha on `window.newspack_grecaptcha`.
 */
interface NewspackGrecaptchaClient {
	version?: string;
	render( forms?: HTMLFormElement[], onSuccess?: ( ( message?: string ) => void ) | null, onError?: ( ( message?: string ) => void ) | null ): void;
	destroy?( forms?: HTMLFormElement[] ): void;
}

/**
 * Gravity Forms' front-end API (subset used to detect newsletter signups).
 */
interface NewspackGravityFormsApi {
	utils?: {
		addAsyncFilter?: ( hookName: string, callback: ( data: unknown ) => Promise< unknown > ) => void;
	};
}

declare const gform: NewspackGravityFormsApi;

interface Window {
	newspack_reader_data?: NewspackReaderData;
	newspack_reader_activation_labels: NewspackReaderActivationLabels;
	/** GA4 gtag, when Site Kit / analytics is active. */
	gtag?: ( ...args: unknown[] ) => void;
	grecaptcha?: NewspackGoogleRecaptchaApi;
	newspack_grecaptcha?: NewspackGrecaptchaClient;
	gform?: NewspackGravityFormsApi;
	/** Legacy IE clipboard fallback read by the OTP input's paste handler. */
	clipboardData?: DataTransfer;
}
