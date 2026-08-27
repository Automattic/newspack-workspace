/**
 * Option sources for access rules whose choices are fetched at edit time rather than
 * localised with the page, shared by the Audience wizard's gate editor and the block
 * editor's visibility panel.
 *
 * The fetched list replaces the localised one, so it has to be complete: a value no
 * option describes renders as "not listed" and loses its suggestion with it, so a
 * truncated list turns real, still-granting IDs into entries a publisher can only
 * remove. `per_page` is capped at 100 by the REST API while `Institution::get_options()`
 * has no such cap, so these requests ask for `per_page=-1` — api-fetch's fetch-all
 * middleware then walks the collection's pages and returns the whole thing.
 *
 * Walking the collection costs one round trip per hundred items, so each rule's list is
 * fetched once and every later reader resolves from that same promise. Writes to the
 * fetched collection drop the entry through `invalidateAccessRuleOptions()`.
 */

/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies.
 */
import type { AccessRuleOption } from './access-rule-options';

type InstitutionItem = { id: number; title: { raw: string } };

/**
 * Slug of the institution access rule, whose options are the site's published
 * institutions. Exported so the views that write them can invalidate the fetched list.
 */
export const INSTITUTION_RULE_SLUG = 'institution';

/**
 * Rules whose options are fetched rather than localised, keyed by rule slug.
 */
const ACCESS_RULE_OPTION_SOURCES: Record< string, () => Promise< AccessRuleOption[] > > = {
	[ INSTITUTION_RULE_SLUG ]: async () => {
		// Ordered by title to match `Institution::get_options()`, so the picker reads the
		// same way whichever list rendered it.
		const items = await apiFetch< InstitutionItem[] >( {
			path: '/wp/v2/np_institution?context=edit&orderby=title&order=asc&per_page=-1&_fields=id,title',
		} );
		return items.map( item => ( { value: item.id, label: item.title.raw } ) );
	},
};

/**
 * Lists already requested, keyed by rule slug.
 *
 * A screen mounts many readers of the same list — a picker per active rule, a summary
 * card per gate — and the block editor remounts its picker on every block selection, so
 * without this each of them would walk the collection again. A rejected request is
 * dropped so the next mount retries; a resolved one is kept until a write invalidates it.
 */
const requests = new Map< string, Promise< AccessRuleOption[] > >();

/**
 * Drops a rule's fetched list, so the next reader fetches it again.
 *
 * The Audience wizard creates, edits and deletes institutions without a page load, so
 * its institution views call this after a write to keep the pickers and summaries naming
 * what the site now has.
 *
 * @param slug The rule slug.
 */
export function invalidateAccessRuleOptions( slug: string ): void {
	requests.delete( slug );
}

/**
 * Whether a rule's values are picked from a list rather than typed as free text.
 *
 * Asked of the rule, not of the list it currently holds. A fetched list is legitimately
 * empty for a moment — every institution deleted, say — and a picker that read that as
 * "this rule has no options" would drop to the free-text control, which writes a string
 * over the IDs the rule still stores.
 *
 * @param slug             The rule slug.
 * @param localisedOptions The rule's options as localised with the page.
 *
 * @return Whether the rule is backed by an option list.
 */
export function isOptionBackedAccessRule( slug: string, localisedOptions: AccessRuleOption[] ): boolean {
	return localisedOptions.length > 0 || undefined !== ACCESS_RULE_OPTION_SOURCES[ slug ];
}

/**
 * The option source for a rule, if its options are fetched.
 *
 * @param slug The rule slug.
 *
 * @return The source, or undefined when the rule's options are localised.
 */
export function getAccessRuleOptionSource( slug: string ): ( () => Promise< AccessRuleOption[] > ) | undefined {
	const source = ACCESS_RULE_OPTION_SOURCES[ slug ];
	if ( ! source ) {
		return undefined;
	}
	return () => {
		const existing = requests.get( slug );
		if ( existing ) {
			return existing;
		}
		const request: Promise< AccessRuleOption[] > = source().catch( error => {
			// Only if it is still the current entry: a write may have invalidated this
			// one and started a fresh request while this was in flight.
			if ( requests.get( slug ) === request ) {
				requests.delete( slug );
			}
			throw error;
		} );
		requests.set( slug, request );
		return request;
	};
}
