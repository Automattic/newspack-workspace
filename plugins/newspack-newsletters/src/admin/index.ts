// The admin nag script is only enqueued alongside jQuery and the localized
// params, so both globals are assumed present; the assertions preserve the
// original throw-on-absence behavior.
const jQuery = window && window.jQuery;

jQuery!( document ).ready( () => {
	jQuery!( document ).on( 'click', '.newspack-newsletters-notification-nag .notice-dismiss', () => {
		const data = {
			action: 'newspack_newsletters_activation_nag_dismissal',
		};
		const { ajaxurl } = window && window.newspack_newsletters_activation_nag_dismissal_params!;
		jQuery!.post( ajaxurl, data, () => null );
	} );
} );
