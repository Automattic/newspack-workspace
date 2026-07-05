/**
 * Cross-unit Newspack window globals: the reader-activation contract exposed
 * by newspack-plugin (src/reader-activation) and consumed by other plugins
 * (blocks, popups, newsletters) and themes. Globals used by a single unit
 * belong in that unit's own declaration file, not here.
 *
 * This file is a global script (no top-level imports; inline import() types
 * only). The shapes below mirror the real client implementation in
 * newspack-plugin/src/reader-activation — that implementation is the source
 * of truth for this contract.
 */

/**
 * Audience Management front-end config, localized as `newspack_ras_config` by
 * Reader_Activation::enqueue_scripts(). Members are optional because
 * consumers defensively seed `window.newspack_ras_config = window.newspack_ras_config || {}`.
 */
interface NewspackRasConfig {
	/** Client ID cookie name (NEWSPACK_CLIENT_ID_COOKIE_NAME). */
	cid_cookie?: string;
	is_logged_in?: boolean;
	/** Current WP user's email when logged in as a reader; '' when anonymous. */
	authenticated_email?: string;
	otp_auth_action?: string;
	/** OTP resend rate limit, in seconds. */
	otp_rate_interval?: number;
	auth_action_result?: string;
	account_url?: string;
	is_ras_enabled?: boolean;
	/** admin-ajax URL for the post-registration verification OTP request. */
	verification_url?: string;
	/** REST URL of the pre-registration check-email endpoint. */
	check_email_url?: string;
	/** Whether new reader accounts require post-registration email verification. */
	verify_new_reader_accounts?: boolean;
	captcha_site_key?: string;
	captcha_version?: string;
	/** REST URL of the frontend integration registration endpoint. */
	frontend_registration_url?: string;
	/** Registered frontend integrations, keyed by integration ID. */
	frontend_registration_integrations?: Record< string, { key?: string } >;
	[ key: string ]: unknown;
}

declare const newspack_ras_config: NewspackRasConfig;

/**
 * The reader as persisted in the reader store ('reader' key). Extra keys may
 * be present (the store value is JSON round-tripped).
 */
interface NewspackReader {
	email?: string;
	authenticated?: boolean;
	[ key: string ]: unknown;
}

/**
 * A reader activity dispatched through `dispatchActivity()` or the
 * `newspackRAS` queue (e.g. `article_view`, `reader_registered`).
 */
interface NewspackReaderActivity {
	action: string;
	data: Record< string, unknown >;
	timestamp: number;
}

/**
 * The reader data store. Values are JSON round-tripped through
 * localStorage/sessionStorage, so reads are `unknown` — narrow at the caller.
 */
interface NewspackReaderActivationStore {
	/** Get a value from the store. Null if not set. */
	get( key: string ): unknown;
	/** Get all public (non-internal) values from the store. */
	getAll(): Record< string, unknown >;
	/** Set a value. `sync` (default true) queues a server write. */
	set( key: string, value: unknown, sync?: boolean ): void;
	/** Delete a value. */
	delete( key: string ): void;
	/** Add a value to a capped, age-pruned collection. */
	add( key: string, value: unknown ): void;
	/** Register a merge strategy used to reconcile server and client values on rehydration. */
	register( key: string, options: { merge: ( serverValue: unknown, clientValue: unknown ) => unknown } ): void;
	/** Rehydrate items from server data. Call after all merge strategies are registered. */
	rehydrate( items?: Record< string, unknown > ): void;
}

/**
 * Registry of currently-open overlays (prompts, modals), tracked by ID.
 */
interface NewspackReaderActivationOverlays {
	get(): string[];
	add( overlayId?: string ): string;
	remove( overlayId?: string ): string[];
}

/**
 * A reader segment definition, as registered by newspack-popups.
 */
interface NewspackRasSegment {
	name?: string;
	criteria?: unknown;
	priority?: number;
	[ key: string ]: unknown;
}

/**
 * Reader segmentation API: segment registry and current match.
 */
interface NewspackReaderActivationSegments {
	register( segments: Record< string, NewspackRasSegment > ): void;
	setMatch( segmentId?: string | number | null ): ( NewspackRasSegment & { id: string } ) | null;
	getMatch(): ( NewspackRasSegment & { id: string } ) | null;
	getAll(): Record< string, NewspackRasSegment >;
}

/**
 * Detail payloads of the reader-activation events, keyed by local event name
 * (the names accepted by `readerActivation.on()`/`off()`).
 */
interface NewspackReaderActivationEventMap {
	reader: NewspackReader;
	data: { key: string; value: unknown };
	activity: NewspackReaderActivity;
	overlay: { overlays: string[]; added?: string; removed?: string };
	segment: {
		segmentId: string | null;
		segment: NewspackRasSegment | null;
		all: Record< string, NewspackRasSegment >;
	};
	session: {
		nonce?: string;
		reader_data_items?: Record< string, unknown >;
		[ key: string ]: unknown;
	};
}

/**
 * Response data passed through the auth flows (register/signin/OTP
 * endpoints). Server-shaped; only commonly-read members are declared.
 */
interface NewspackAuthResponseData {
	email?: string;
	authenticated?: boolean;
	registered?: boolean;
	existing_user?: boolean;
	verified?: boolean;
	verification_nonce?: string;
	sso?: boolean;
	password_url?: string;
	redirect_to?: string;
	action?: string;
	message?: string;
	metadata?: {
		gate_post_id?: string | number;
		newspack_popup_id?: string | number;
		login_method?: string;
		registration_method?: string;
		[ key: string ]: unknown;
	};
	[ key: string ]: unknown;
}

/**
 * Labels for the auth modal. The full label set localized by newspack-plugin
 * satisfies this shape; openers may pass a subset override.
 */
interface NewspackAuthModalLabels {
	signin: { title: string; [ key: string ]: unknown };
	register: { title: string; [ key: string ]: unknown };
	[ key: string ]: unknown;
}

/**
 * Configuration accepted by `openAuthModal()`.
 */
interface NewspackAuthModalConfig {
	onSuccess?: ( ( message?: string | null, data?: NewspackAuthResponseData | null ) => void ) | null;
	onDismiss?: ( () => void ) | null;
	onError?: ( () => void ) | null;
	onClose?: ( ( message?: string | null, data?: NewspackAuthResponseData | null ) => void ) | null;
	initialState?: string | null;
	skipSuccess?: boolean;
	skipNewslettersSignup?: boolean;
	skipAuthenticatedCheck?: boolean;
	/** Make the form's "Back" button close the modal instead of returning to the sign-in step. */
	backButtonClosesModal?: boolean;
	labels?: NewspackAuthModalLabels;
	content?: string | null;
	trigger?: HTMLElement | null;
	closeOnSuccess?: boolean;
}

/**
 * Configuration accepted by `openNewslettersSignupModal()`.
 */
interface NewspackNewslettersSignupModalConfig {
	onSuccess?: ( ( message?: unknown, data?: unknown ) => void ) | null;
	onDismiss?: ( () => void ) | null;
	onError?: ( () => void ) | null;
	initialState?: string | null;
	skipSuccess?: boolean;
	labels?: Record< string, unknown >;
	content?: string | null;
	closeOnSuccess?: boolean;
	signupMethod?: string;
}

/**
 * Configuration accepted by `openVerificationModal()` (post-registration
 * email verification).
 */
interface NewspackVerificationModalConfig {
	email?: string;
	verificationNonce?: string;
	setOTPTimer?: () => void;
	onSendCode?: () => void;
	onDismiss?: () => void;
}

/**
 * Optional profile fields for frontend integration registration.
 */
interface NewspackFrontendRegistrationFields {
	first_name?: string;
	last_name?: string;
	metadata?: Record< string, unknown >;
}

/**
 * The reader-activation client (window.newspackReaderActivation), implemented
 * by newspack-plugin/src/reader-activation/index.
 *
 * Optional members are attached at runtime by separate bundles (the auth and
 * newsletters-signup bundles) or gated on site configuration
 * (`openAuthModal` only exists when Audience Management is enabled) — gate
 * access with a `typeof` check before calling.
 */
interface NewspackReaderActivation {
	store: NewspackReaderActivationStore;
	overlays: NewspackReaderActivationOverlays;
	segments: NewspackReaderActivationSegments;
	on< K extends keyof NewspackReaderActivationEventMap >(
		event: K,
		callback: ( event: CustomEvent< NewspackReaderActivationEventMap[ K ] > ) => void
	): void;
	off< K extends keyof NewspackReaderActivationEventMap >(
		event: K,
		callback: ( event: CustomEvent< NewspackReaderActivationEventMap[ K ] > ) => void
	): void;
	dispatchActivity( action: string, data: Record< string, unknown >, timestamp?: number ): NewspackReaderActivity;
	getActivities( action?: string ): NewspackReaderActivity[];
	getUniqueActivitiesBy( action: string, iteratee: string | ( ( activity: NewspackReaderActivity ) => unknown ) ): NewspackReaderActivity[];
	setReaderEmail( email: string ): void;
	setAuthenticated( authenticated?: boolean ): void;
	refreshAuthentication(): void;
	getReader(): NewspackReader;
	openNewslettersSignupModal( config?: NewspackNewslettersSignupModalConfig ): void;
	hasAuthLink(): boolean;
	getOTPHash(): string;
	setOTPTimer(): void;
	clearOTPTimer(): void;
	getOTPTimeRemaining(): number;
	authenticateOTP( code: string ): Promise< NewspackAuthResponseData >;
	setAuthStrategy( strategy: string ): string;
	getAuthStrategy(): string;
	setPendingCheckout( url?: string | false ): void;
	getPendingCheckout(): string | false;
	debugLog( level?: string, ...args: unknown[] ): void;
	register( email: string, integrationId: string, profileFields?: NewspackFrontendRegistrationFields ): Promise< NewspackAuthResponseData >;
	/** Present only when Audience Management is enabled on the site. */
	openAuthModal?( config?: NewspackAuthModalConfig ): void;
	/** Attached at runtime by the reader-activation-auth bundle. */
	_openAuthModal?( config?: NewspackAuthModalConfig ): void;
	/** Attached at runtime by the reader-activation-auth bundle. */
	openVerificationModal?( config?: NewspackVerificationModalConfig ): boolean;
	/** Attached at runtime by the reader-activation-auth bundle. */
	maybeConfirmRegistration?( args: { email: string; onProceed: () => void; onCancel?: () => void } ): void;
	/** Attached at runtime by the newsletters-signup bundle. */
	_openNewslettersSignupModal?( config?: NewspackNewslettersSignupModalConfig ): void;
	/** Attached at runtime by the newsletters-signup bundle. */
	refreshNewslettersSignupModal?(): Promise< void >;
	[ key: string ]: unknown;
}

/**
 * Items accepted by the pre-init `newspackRAS` queue: a callback receiving
 * the client once ready, or an `[ action, data, timestamp? ]` reader-activity
 * tuple (analogous to the gtag/dataLayer pattern).
 */
type NewspackRASQueueItem = ( ( readerActivation: NewspackReaderActivation ) => void ) | [ string, Record< string, unknown >, number? ];

/**
 * The `window.newspackRAS` command queue. Before the client initializes it is
 * a plain array; on init the client drains it and replaces its `push` with a
 * live dispatcher (which returns nothing) — hence the queue is declared as
 * this minimal, push-focused shape rather than a full Array.
 */
interface NewspackRASQueue {
	push( ...items: NewspackRASQueueItem[] ): unknown;
	forEach( callback: ( item: NewspackRASQueueItem, index: number ) => void ): void;
	readonly length: number;
}

interface Window {
	/**
	 * Command queue for the reader-activation client. Declared non-optional
	 * because every consumer must seed it via
	 * `window.newspackRAS = window.newspackRAS || []` before pushing — the
	 * established pattern across all Newspack bundles.
	 */
	newspackRAS: NewspackRASQueue;
	/** The client itself; absent until the reader-activation bundle initializes. */
	newspackReaderActivation?: NewspackReaderActivation;
	newspackRASInitialized?: boolean;
	/** Audience Management front-end config; seeded to `{}` by consumers when absent. */
	newspack_ras_config?: NewspackRasConfig;
	/**
	 * Modal checkout opener, exposed by newspack-blocks' modal-checkout script
	 * when reader checkout is enabled — gate on presence before calling.
	 */
	newspackOpenModalCheckout?( config: {
		url?: string | null;
		title?: string | null;
		actionType?: string;
		onCheckoutComplete?: ( data?: unknown ) => void;
		onClose?: () => void;
		[ key: string ]: unknown;
	} ): void;
}
