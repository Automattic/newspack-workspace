// Boot's PHP config can only name dashicons; swap in @wordpress/icons once the
// menu is registered and before the app renders.
export async function init() {
	const icons = window.newspackAdminApp?.icons || {};
	const { dispatch } = window.wp.data;
	Object.entries( icons ).forEach( ( [ id, icon ] ) => dispatch( 'wordpress/boot' ).updateMenuItem( id, { icon } ) );
}
