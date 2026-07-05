/**
 * Shared types for the reader-activation-auth entry.
 *
 * The auth flow attaches imperative state and callbacks to a handful of DOM
 * elements at runtime. The form element itself needs no augmentation here —
 * lib.dom's `HTMLFormElement` carries a `[name: string]: any` index signature,
 * so the flow methods (`setMessageContent`, `startLoginFlow`, `endLoginFlow`,
 * `isVerifying`) are assignable/readable through it. The container, modal and
 * per-action item elements have no such index signature, so their runtime
 * members are declared below.
 */

/**
 * The `.newspack-reader-auth` container element, augmented with the imperative
 * API the auth form wires up (`setFormAction`) and the state the modal opener
 * attaches (`config`, `authCallback`, `formActionCallback`).
 */
export interface AuthContainerElement extends HTMLElement {
	setFormAction: ( action: string | null, shouldFocus?: boolean ) => void;
	config?: NewspackAuthModalConfig;
	authCallback?: ( message?: string | null, data?: NewspackAuthResponseData | null ) => void;
	formActionCallback?: ( action: string ) => void;
}

/**
 * The `.newspack-reader-auth-modal` element, augmented with the overlay ID
 * assigned when the modal registers with the reader-activation overlays API.
 */
export interface AuthModalElement extends HTMLElement {
	overlayId?: string;
}

/**
 * A `[data-action]` element whose pre-hide `display` value is stashed so it can
 * be restored when its action step becomes active again.
 */
export interface ActionItemElement extends HTMLElement {
	prevDisplay?: string;
}

declare global {
	interface Document {
		/** Set once the auth form has initialized (drives onAuthFormReady). */
		_newspackReaderAuthFormReady?: boolean;
	}
}
