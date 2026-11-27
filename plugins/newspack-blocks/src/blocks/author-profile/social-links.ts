/**
 * Internal dependencies
 */
import type { Author, AuthorSocialLink } from './single-author';

/**
 * Combine an author's social links with the contact links a block chooses to show.
 *
 * Returns a new object. Author records are shared between every block on the page that shows
 * the same author, so a block must not write its own choices into the record it was given.
 *
 * @param author             Author record from the authors endpoint.
 * @param options
 * @param options.showSocial Whether to include the social links.
 * @param options.showEmail  Whether to include the email link.
 * @param options.showPhone  Whether to include the phone number link.
 * @return Links keyed by service.
 */
export function getSocialLinks(
	author: Author | null | undefined,
	{ showSocial, showEmail, showPhone }: { showSocial?: boolean; showEmail?: boolean; showPhone?: unknown }
): Record< string, AuthorSocialLink > {
	const links: Record< string, AuthorSocialLink > = { ...( ( showSocial && author?.social ) || {} ) };
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
