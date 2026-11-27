/**
 * Browser globals for the handoff-banner block-editor notice.
 *
 * Global-script form (no top-level imports/exports) so the declarations land in
 * global scope and merge with the shared NewspackWpGlobal in shared/globals.d.ts.
 */

interface NewspackHandoffData {
	text: string;
	returnURL: string;
	buttonText: string;
}

declare const newspack_handoff: NewspackHandoffData;

interface NewspackWpNotice {
	createNotice(
		status: string,
		content: string,
		options: { isDismissible?: boolean; actions?: { url: string; label: string }[] }
	): void;
}

interface NewspackWpGlobal {
	data: {
		dispatch( store: string ): NewspackWpNotice;
	};
}
