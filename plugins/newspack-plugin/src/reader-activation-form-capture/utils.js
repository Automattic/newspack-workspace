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
 * falling back to name/id/autocomplete heuristics on text inputs. Iterates
 * all candidates in order and returns the first one with a valid value, so
 * an empty honeypot or confirmation field ahead of the real one is skipped.
 *
 * @param {HTMLFormElement} form Form element.
 * @return {string} The email value, or empty string.
 */
export function getEmailValue( form ) {
	const candidates = [
		...form.querySelectorAll( 'input[type="email"]' ),
		...Array.from( form.querySelectorAll( 'input[type="text"], input:not([type])' ) ).filter( candidate =>
			/e-?mail/i.test( attrs( candidate ) )
		),
	];
	for ( const input of candidates ) {
		const value = input?.value?.trim() || '';
		if ( EMAIL_PATTERN.test( value ) ) {
			// Lower-case at harvest so the client-side dedupe and current-reader
			// checks agree with the server, which matches emails
			// case-insensitively — Reader@example.com and reader@example.com
			// from the same visitor are one capture, not two.
			return value.toLowerCase();
		}
	}
	return '';
}

/**
 * Best-effort first/last name harvest from a form.
 *
 * @param {HTMLFormElement} form Form element.
 * @return {Object} { first_name, last_name } or an empty object.
 */
export function getNameValues( form ) {
	const inputs = Array.from( form.querySelectorAll( 'input[type="text"], input:not([type])' ) );
	const valueMatching = ( pattern, reject = null ) =>
		inputs
			.find( input => {
				const haystack = attrs( input );
				return pattern.test( haystack ) && ! ( reject && reject.test( haystack ) );
			} )
			?.value?.trim() || '';
	const firstName = valueMatching( /first[-_ ]?name|fname|given-name/i );
	const lastName = valueMatching( /last[-_ ]?name|lname|family-name|surname/i );
	if ( firstName || lastName ) {
		return { first_name: firstName, last_name: lastName };
	}
	// A bare "name" field is only trusted when it isn't qualified as some
	// other kind of name — organization_name, display-name, file name — which
	// would set the reader's first name to a company or a handle.
	const fullName = valueMatching(
		/(^|[-_ [])name([-_ \]]|$)/i,
		/(org|organi[sz]ation|business|company|user|display|file|nick|site|domain|host)[-_ ]?name/i
	);
	return fullName ? { first_name: fullName, last_name: '' } : {};
}
