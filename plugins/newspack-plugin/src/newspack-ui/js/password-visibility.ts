/**
 * Show or hide the password in a newspack-ui password field, keeping its toggle labelled.
 *
 * @param field   The `.newspack-ui__password-field` wrapper.
 * @param visible Whether the password should be shown.
 */
export function setPasswordVisible( field: Element | null | undefined, visible: boolean ): void {
	const input = field?.querySelector( 'input' );
	if ( ! input ) {
		return;
	}
	input.type = visible ? 'text' : 'password';
	// `input` was found through `field`, so `field` is present here.
	const toggle = field!.querySelector< HTMLElement >( '.newspack-ui__password-toggle' );
	const label = visible ? toggle?.dataset.labelHide : toggle?.dataset.labelShow;
	if ( label ) {
		toggle!.setAttribute( 'aria-label', label );
	}
}
