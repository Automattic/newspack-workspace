/**
 * Show or hide the password in a newspack-ui password field, keeping its toggle labelled.
 *
 * @param {HTMLElement|null} field   The `.newspack-ui__password-field` wrapper.
 * @param {boolean}          visible Whether the password should be shown.
 */
export function setPasswordVisible( field, visible ) {
	const input = field?.querySelector( 'input' );
	if ( ! input ) {
		return;
	}
	input.type = visible ? 'text' : 'password';
	const toggle = field.querySelector( '.newspack-ui__password-toggle' );
	const label = visible ? toggle?.dataset.labelHide : toggle?.dataset.labelShow;
	if ( label ) {
		toggle.setAttribute( 'aria-label', label );
	}
}
