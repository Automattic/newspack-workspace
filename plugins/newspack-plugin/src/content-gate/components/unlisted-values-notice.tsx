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

export default function UnlistedValuesNotice( { options, value }: { options: AccessRuleOption[]; value: unknown } ) {
	const hasUnlisted = hasUnlistedAccessRuleValues( options, value );

	useEffect( () => {
		if ( hasUnlisted ) {
			speak( getUnlistedAccessRuleValuesNotice(), 'polite' );
		}
	}, [ hasUnlisted ] );

	if ( ! hasUnlisted ) {
		return null;
	}
	return (
		<p role="note" className="newspack-access-rule-values-notice">
			{ getUnlistedAccessRuleValuesNotice() }
		</p>
	);
}
