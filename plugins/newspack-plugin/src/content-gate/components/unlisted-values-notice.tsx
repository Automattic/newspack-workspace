/**
 * Caution shown under a picker holding stored values no option describes, so the reading
 * the token invites — stale entry, safe to delete — does not go unchallenged.
 *
 * Core's `FormTokenField` points its input's `aria-describedby` at its own "how to"
 * element and takes no second description, so this cannot be associated with the field
 * it belongs to. It is spoken instead, which is the one route that reaches a screen
 * reader before the publisher removes a token.
 */

/**
 * WordPress dependencies.
 */
import { speak } from '@wordpress/a11y';
import { useEffect } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { getUnlistedAccessRuleValuesNotice, hasUnlistedAccessRuleValues, type AccessRuleOption } from '../access-rule-options';

/**
 * Rules whose caution has been spoken in this page's lifetime.
 *
 * Module scope rather than component state, because the repetition to suppress happens
 * across mounts: the block inspector unmounts on deselection, so an effect keyed on the
 * component's own lifetime read the whole paragraph out again every time a publisher
 * clicked between two blocks carrying an unlisted value. `@wordpress/a11y` will not
 * absorb the repeat either — it appends a non-breaking space to a message matching the
 * previous one, precisely to force a re-announcement. Keyed by rule, because the wording
 * names what that rule's picker can fail to list: a second rule's caution says something
 * the first did not.
 */
const slugsSpokenFor = new Set< string >();

export default function UnlistedValuesNotice( { slug, options, value }: { slug: string; options: AccessRuleOption[]; value: unknown } ) {
	const hasUnlisted = hasUnlistedAccessRuleValues( options, value );

	useEffect( () => {
		if ( hasUnlisted && ! slugsSpokenFor.has( slug ) ) {
			slugsSpokenFor.add( slug );
			speak( getUnlistedAccessRuleValuesNotice( slug ), 'polite' );
		}
	}, [ hasUnlisted, slug ] );

	if ( ! hasUnlisted ) {
		return null;
	}
	return (
		<p role="note" className="newspack-access-rule-values-notice">
			{ getUnlistedAccessRuleValuesNotice( slug ) }
		</p>
	);
}
