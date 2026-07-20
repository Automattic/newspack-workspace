/**
 * Ambient declarations for the `newspack-ui` unit. Global-script form
 * (no top-level imports/exports) so the Window merge applies globally.
 */

interface Window {
	/** Public API published by the newspack-ui entry (src/newspack-ui/js/index.ts). */
	newspackUI?: {
		notices?: {
			openNotice: ( element: HTMLElement, remove?: boolean ) => void;
			closeNotice: ( element: HTMLElement, remove?: boolean ) => void;
			createNotice: ( message: string, type?: string ) => void;
		};
	};
}
