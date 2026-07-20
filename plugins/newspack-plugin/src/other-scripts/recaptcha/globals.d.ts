/**
 * Ambient declarations for the `recaptcha` entry. Global-script form
 * (no top-level imports/exports) so every declaration lands in the global scope.
 */

/**
 * The Google reCAPTCHA API (v2 and v3 surfaces used by this entry).
 *
 * @see https://developers.google.com/recaptcha/docs/invisible
 * @see https://developers.google.com/recaptcha/docs/v3
 */
interface GRecaptcha {
	ready( callback: () => void ): void;
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
	/** v3: get a token for the given site key and action. */
	execute( siteKey: string, options: { action: string } ): PromiseLike< string >;
	/** v2: programmatically invoke the widget. */
	execute( widgetId?: string | number | null ): void;
	reset( widgetId?: number ): void;
}

// Undefined until the Google API script has loaded; callers guard or defer
// (via domReady) accordingly.
declare const grecaptcha: GRecaptcha | undefined;

/**
 * Data localized by Recaptcha (includes/class-recaptcha.php).
 */
declare const newspack_recaptcha_data: {
	site_key: string;
	/** 'v2_checkbox', 'v2_invisible' or 'v3'. */
	version: string;
	/** The Google API script URL. */
	api_url: string;
};

interface Window {
	/** Public API published by this entry for other Newspack scripts. */
	newspack_grecaptcha?: {
		destroy: ( forms?: HTMLFormElement[] ) => void;
		render: ( forms?: HTMLFormElement[], onSuccess?: ( () => void ) | null, onError?: ( ( message: string ) => void ) | null ) => void;
		version: string;
	};
}
