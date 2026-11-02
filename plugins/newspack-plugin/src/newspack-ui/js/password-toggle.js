/**
 * Show-password toggles inside newspack-ui password fields.
 *
 * Uses event delegation so toggles in markup added after load still respond.
 */

/**
 * Internal dependencies
 */
import { setPasswordVisible } from './password-visibility';

document.addEventListener( 'click', event => {
	const toggle = event.target.closest?.( '.newspack-ui__password-toggle' );
	const field = toggle?.closest( '.newspack-ui__password-field' );
	const input = field?.querySelector( 'input' );
	if ( ! input ) {
		return;
	}
	event.preventDefault();
	setPasswordVisible( field, 'password' === input.type );
} );
