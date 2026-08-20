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
 * Rules whose options are fetched rather than localised, keyed by rule slug.
 */
const ACCESS_RULE_OPTION_SOURCES: Record< string, () => Promise< AccessRuleOption[] > > = {
	institution: async () => {
		// Ordered by title to match `Institution::get_options()`, so the picker reads the
		// same way whichever list rendered it.
		const items = await apiFetch< InstitutionItem[] >( {
			path: '/wp/v2/np_institution?context=edit&orderby=title&order=asc&per_page=-1',
		} );
		return items.map( item => ( { value: item.id, label: item.title.raw } ) );
	},
};

/**
 * The option source for a rule, if its options are fetched.
 *
 * @param slug The rule slug.
 *
 * @return The source, or undefined when the rule's options are localised.
 */
export function getAccessRuleOptionSource( slug: string ): ( () => Promise< AccessRuleOption[] > ) | undefined {
	return ACCESS_RULE_OPTION_SOURCES[ slug ];
}
