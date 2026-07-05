/**
 * Ambient globals for newspack-newsletters: window-scoped data localized by
 * PHP, cross-bundle registries, jQuery, and non-code asset imports.
 *
 * This is a global script (no top-level imports/exports), so the `Window` and
 * asset-module augmentations below apply across the whole unit. Module-scoped
 * shared shapes (the `ServiceProvider` contract and ESP data types) live in
 * `service-providers/types.ts` and are imported normally; only inline
 * `import()` types are used here to keep this file global.
 */

/**
 * Editor/front-end config localized as `newspack_newsletters_data`. Only the
 * commonly-read members are typed; the bag carries additional server-shaped
 * keys.
 */
interface NewspackNewslettersData {
	service_provider?: string;
	is_service_provider_configured?: boolean;
	user_test_emails?: string[];
	[ key: string ]: unknown;
}

/** Editor config localized as `newspack_newsletters_editor_data`. */
interface NewspackNewslettersEditorData {
	mailchimp_default_footer?: string;
	[ key: string ]: unknown;
}

/** Email-editor config localized as `newspack_email_editor_data`. */
interface NewspackEmailEditorDataLEGACYDUP {}

/** Admin config localized as `newspackNewslettersAdmin`. */
interface NewspackNewslettersAdmin {
	adminUrl?: string;
	bundledMode?: boolean;
	label?: string;
	[ key: string ]: unknown;
}

/** Config localized for the wizard bridge bootstrap. */
interface NewspackNewslettersWizardBridge {
	debug?: boolean;
	[ key: string ]: unknown;
}

/** Params localized for the activation-nag dismissal AJAX call. */
interface NewspackNewslettersActivationNagParams {
	ajaxurl: string;
	[ key: string ]: unknown;
}

/**
 * The `window.newspack.newsletters` registry namespace used to register
 * local-list-modal extensions across bundles.
 */
interface NewspackNewslettersNamespace {
	_pendingExtensions?: Array< [ string, import('./wizard-bridge/extensions').LocalListModalExtension ] >;
	registerLocalListModalExtension?: ( id: string, definition: import('./wizard-bridge/extensions').LocalListModalExtension ) => void;
	[ key: string ]: unknown;
}

/** The shared `window.newspack` namespace object. */
interface NewspackGlobal {
	newsletters?: NewspackNewslettersNamespace;
	[ key: string ]: unknown;
}

/** Minimal jQuery surface used by the activation-nag admin script. */
interface NewspackJQueryInstance {
	ready( handler: () => void ): NewspackJQueryInstance;
	on( events: string, selector: string, handler: () => void ): NewspackJQueryInstance;
}
interface NewspackJQueryStatic {
	( selector: Document | string ): NewspackJQueryInstance;
	post( url: string, data: unknown, success: () => void ): unknown;
}

interface Window {
	newspack_newsletters_data?: NewspackNewslettersData;
	newspack_newsletters_editor_data?: NewspackNewslettersEditorData;
	newspack_email_editor_data?: NewspackEmailEditorData;
	newspackNewslettersAdmin?: NewspackNewslettersAdmin;
	newspack_newsletters_wizard_bridge?: NewspackNewslettersWizardBridge;
	newspack_newsletters_activation_nag_dismissal_params?: NewspackNewslettersActivationNagParams;
	newspack?: NewspackGlobal;
	/** Live event-name map exposed by the wizard bridge (`EVENTS`). */
	newspackNewslettersEvents?: Record< string, string >;
	/** Global CSS injected into layout previews, localized as a CSS string. */
	newspackNewslettersGlobalStyles?: string;
	/** Set true by the wizard-bridge host once its listeners are installed. */
	newspackNewslettersBridgeReady?: boolean;
	/** Cross-bundle registry Map for local-list-modal extensions. */
	__newspackNewslettersLocalListModalExtensions?: Map< string, import('./wizard-bridge/extensions').LocalListModalExtension >;
	jQuery?: NewspackJQueryStatic;
}

/** Style imports resolved by webpack; carry no runtime value in TS. */
declare module '*.scss';
