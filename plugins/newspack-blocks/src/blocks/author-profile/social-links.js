/**
 * Combine an author's social links with the contact links a block chooses to show.
 *
 * Returns a new object. Author records are shared between every block on the page that shows
 * the same author, so a block must not write its own choices into the record it was given.
 *
 * @param {Object}  author             Author record from the authors endpoint.
 * @param {Object}  options
 * @param {boolean} options.showSocial Whether to include the social links.
 * @param {boolean} options.showEmail  Whether to include the email link.
 * @param {boolean} options.showPhone  Whether to include the phone number link.
 * @return {Object} Links keyed by service.
 */
export function getSocialLinks( author, { showSocial, showEmail, showPhone } ) {
	const links = { ...( ( showSocial && author?.social ) || {} ) };
	delete links.email;
	delete links.newspack_phone_number;
	if ( showEmail && author?.email ) {
		links.email = author.email;
	}
	if ( showPhone && author?.newspack_phone_number ) {
		links.newspack_phone_number = author.newspack_phone_number;
	}
	return links;
}
