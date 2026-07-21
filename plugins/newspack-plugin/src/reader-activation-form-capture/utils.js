const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const attrs = input => `${ input.name || '' } ${ input.id || '' } ${ input.getAttribute( 'autocomplete' ) || '' }`;

/**
 * Resolve the configured selectors to a unique list of form elements.
 * Non-form matches resolve to their closest or first inner form; invalid
 * selectors (publisher-supplied) are ignored.
 *
 * @param {string[]}         selectors CSS selectors.
 * @param {Element|Document} root      Root to query. Defaults to document.
 * @return {HTMLFormElement[]} Matched forms.
 */
export function getMatchedForms( selectors, root = document ) {
	const forms = new Set();
	selectors.forEach( selector => {
		let matches = [];
		try {
			matches = root.querySelectorAll( selector );
		} catch ( e ) {
			return;
		}
		matches.forEach( el => {
			const form = 'FORM' === el.tagName ? el : el.closest( 'form' ) || el.querySelector( 'form' );
			if ( form ) {
				forms.add( form );
			}
		} );
	} );
	return Array.from( forms );
}

/**
 * Get a valid email value from a form, preferring input[type=email] and
 * falling back to name/id/autocomplete heuristics on text inputs.
 *
 * @param {HTMLFormElement} form Form element.
 * @return {string} The email value, or empty string.
 */
export function getEmailValue( form ) {
	const input =
		form.querySelector( 'input[type="email"]' ) ||
		Array.from( form.querySelectorAll( 'input[type="text"], input:not([type])' ) ).find( candidate => /e-?mail/i.test( attrs( candidate ) ) );
	const value = input?.value?.trim() || '';
	return EMAIL_PATTERN.test( value ) ? value : '';
}

/**
 * Best-effort first/last name harvest from a form.
 *
 * @param {HTMLFormElement} form Form element.
 * @return {Object} { first_name, last_name } or an empty object.
 */
export function getNameValues( form ) {
	const inputs = Array.from( form.querySelectorAll( 'input[type="text"], input:not([type])' ) );
	const valueMatching = pattern => inputs.find( input => pattern.test( attrs( input ) ) )?.value?.trim() || '';
	const firstName = valueMatching( /first[-_ ]?name|fname|given-name/i );
	const lastName = valueMatching( /last[-_ ]?name|lname|family-name|surname/i );
	if ( firstName || lastName ) {
		return { first_name: firstName, last_name: lastName };
	}
	const fullName = valueMatching( /(^|[-_ [])name([-_ \]]|$)/i );
	return fullName ? { first_name: fullName, last_name: '' } : {};
}
