/**
 * Ambient declarations for the CSV export admin script. Global-script form
 * (no top-level imports/exports), so the declaration lands in the global scope.
 */

/**
 * Data localized as `newspackCsvExport` by the CSV export admin screen. The
 * AJAX endpoint config plus the UI label strings the script shows.
 */
declare const newspackCsvExport: {
	ajaxUrl: string;
	action: string;
	refreshAction: string;
	nonce: string;
	labels: {
		error: string;
		done: string;
		exporting: string;
		refreshing: string;
		refreshed: string;
		refreshError: string;
	};
};
