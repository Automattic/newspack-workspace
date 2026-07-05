/**
 * Ambient declarations for the group-subscription admin entry.
 *
 * Global-script form (no top-level imports/exports) so the declarations land in
 * the global scope and merge with `src/shared/globals.d.ts`.
 */

/**
 * Payload localized by Group_Subscription_Settings::enqueue_scripts() as
 * `newspackGroupSubscriptions`.
 */
declare const newspackGroupSubscriptions: {
	apiUrl: string;
	apiNonce: string;
	placeholder: string;
	invalid_email_message: string;
	success_message: string;
	pending_label: string;
};

/**
 * The jqXHR subset used by the select2 AJAX transport callbacks.
 */
interface NewspackSelect2Xhr {
	setRequestHeader( name: string, value: string ): void;
	responseJSON?: { message?: string };
}

/**
 * The select2 configuration subset used by the member-search field.
 */
interface NewspackSelect2Options {
	ajax: {
		url: string;
		beforeSend( xhr: NewspackSelect2Xhr ): void;
		type?: string;
		delay?: number;
		data( params: { term: string } ): Record< string, unknown >;
		processResults( data: unknown ): { results: unknown };
		error( xhr: NewspackSelect2Xhr, status: string, error: string ): void;
		cache?: boolean;
	};
	closeOnSelect?: boolean;
	minimumInputLength?: number;
	placeholder?: string;
	allowClear?: boolean;
}

// Merge the select2 plugin method (registered by the `select2` script dependency)
// into the shared jQuery-set surface.
interface NewspackJQuery {
	select2( options: NewspackSelect2Options ): NewspackJQuery;
}
