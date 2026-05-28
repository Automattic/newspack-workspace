const jQuery = window && window.jQuery;

jQuery( document ).ready( () => {
	jQuery( document ).on( 'click', '.newspack-newsletters-notification-nag .notice-dismiss', () => {
		const data = {
			action: 'newspack_newsletters_activation_nag_dismissal',
		};
		const { ajaxurl } = window && window.newspack_newsletters_activation_nag_dismissal_params;
		jQuery.post( ajaxurl, data, () => null );
	} );
} );
