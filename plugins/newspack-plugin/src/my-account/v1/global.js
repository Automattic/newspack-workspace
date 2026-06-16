/**
 * Global functions for My Account pages.
 */

/**
 * Internal dependencies.
 */
import { domReady } from '../../utils';

domReady( () => {
	const interactionElements = [ '.newspack-ui--block-on-interaction' ];
	const blockUIonInteraction = [ ...document.querySelectorAll( interactionElements.join( ',' ) ) ];
	blockUIonInteraction.forEach( element => {
		const parent = element.closest( 'form, div' );
		if (
			( 'button' === element.tagName.toLowerCase() || 'input' === element.tagName.toLowerCase() ) &&
			'form' === parent.tagName.toLowerCase()
		) {
			parent.addEventListener( 'submit', e => {
				e.target.classList.add( 'newspack-ui--loading' );
			} );
		} else {
			element.addEventListener( 'click', e => {
				e.target.closest( 'form, div' ).classList.add( 'newspack-ui--loading' );
			} );
		}
	} );

	// Convert any leftover inline WooCommerce success notice into a snackbar. The PHP
	// `notices/success.php` template override misses some flows (e.g. URL-query message
	// → `wc_add_notice` → `wc_print_notices` after redirect), and WC Blocks renders
	// success messages as `.wc-block-components-notice-banner.is-success` rather than
	// the legacy `.woocommerce-message` markup. Exclude informational notices (e.g. the
	// "no active subscriptions" empty state) which also use `.woocommerce-message`.
	// Uses the global `window.newspackUI.notices` API from the `newspack-ui` script
	// (a dependency of this script) rather than re-importing the module, which would
	// re-run its initializer and duplicate handlers.
	const createNotice = window.newspackUI?.notices?.createNotice;
	if ( createNotice ) {
		document
			.querySelectorAll(
				'.woocommerce-message:not(.woocommerce-info):not(.woocommerce-message--info), .wc-block-components-notice-banner.is-success'
			)
			.forEach( el => {
				const text = el.textContent.trim();
				if ( ! text ) {
					return;
				}
				createNotice( text, 'success' );
				el.remove();
			} );
	}
	document.querySelectorAll( '.woocommerce-notices-wrapper:empty' ).forEach( el => el.remove() );
} );
