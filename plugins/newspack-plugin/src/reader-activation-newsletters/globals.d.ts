/**
 * Ambient declarations for the reader-activation-newsletters entry
 * (src/reader-activation-newsletters). These globals and DOM-element
 * augmentations are consumed only by this unit's modules.
 *
 * Global script file — no top-level imports; use inline import() types only.
 * The cross-plugin reader-activation contract (NewspackReaderActivation,
 * NewspackNewslettersSignupModalConfig, window.newspackRAS) is declared
 * canonically in newspack-scripts/types/newspack-globals.d.ts.
 */

/**
 * Newsletters signup config, localized as `newspack_reader_activation_newsletters`
 * by Newspack_Newsletters reader-activation integration. Consumers seed
 * `window.newspack_reader_activation_newsletters` and read it defensively.
 */
interface NewspackReaderActivationNewsletters {
	/** admin-ajax URL for the newsletters signup request. */
	newspack_ajax_url: string;
	/** REST URL of the reader newsletter signup lists endpoint. */
	newsletters_url?: string;
	[ key: string ]: unknown;
}

declare const newspack_reader_activation_newsletters: NewspackReaderActivationNewsletters;

/**
 * The `.newspack-newsletters-signup` container element. `config` and
 * `newslettersSignupCallback` are attached at runtime by the modal opener and
 * read back by the form handler.
 */
interface NewspackNewslettersSignupContainer extends HTMLElement {
	config?: NewspackNewslettersSignupModalConfig;
	newslettersSignupCallback?: ( message?: unknown, data?: unknown ) => void;
}

/**
 * The `.newspack-newsletters-signup-modal` element. `overlayId` is assigned
 * when the modal registers itself with the reader-activation overlays registry.
 */
interface NewspackNewslettersSignupModalElement extends HTMLElement {
	overlayId?: string;
}

interface Window {
	newspack_reader_activation_newsletters?: NewspackReaderActivationNewsletters;
}
