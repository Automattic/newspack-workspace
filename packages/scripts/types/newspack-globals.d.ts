/**
 * Cross-unit Newspack window globals: the reader-activation contract exposed
 * by newspack-plugin and consumed by other plugins (blocks, popups,
 * newsletters) and themes. Globals used by a single unit belong in that
 * unit's own declaration file, not here.
 *
 * These members are optional because the reader-activation scripts are only
 * present when Audience Management is enabled on the site.
 */
interface NewspackReaderActivation {
	on( event: string, callback: ( event: CustomEvent ) => void ): void;
	off( event: string, callback: ( event: CustomEvent ) => void ): void;
	setReaderEmail( email: string ): void;
	setAuthenticated( authenticated?: boolean ): void;
	refreshAuthentication(): void;
	getReader(): { email?: string; authenticated?: boolean };
	hasAuthLink(): boolean;
	setAuthStrategy( strategy: string ): void;
	getAuthStrategy(): string;
	[ key: string ]: unknown;
}

interface Window {
	/**
	 * Command queue for the reader-activation client: push callbacks that run
	 * once the client is ready (analogous to the gtag/dataLayer pattern).
	 */
	newspackRAS?: Array< ( ras: NewspackReaderActivation ) => void >;
	newspackReaderActivation?: NewspackReaderActivation;
	newspackRASInitialized?: boolean;
}
