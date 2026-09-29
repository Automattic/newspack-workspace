/**
 * The caution about a rule's stored value, rendered under the picker it describes and
 * associated with it.
 *
 * The association is made on a group around the field rather than on the field itself,
 * because core's `FormTokenField` points its input's `aria-describedby` at its own "how
 * to" element and takes no second description — see the same note in
 * `unlisted-values-notice.tsx`. It matters most in the state that has no other route: the
 * caution is often the reason the picker is disabled, and a disabled picker is out of the
 * tab order, so without the group nothing ties the two together.
 *
 * Both surfaces that render an access rule value — the Audience wizard's rule control and
 * the block editor's visibility panel — go through this, so one stored value reads the
 * same way on either.
 */

/**
 * WordPress dependencies.
 */
import { useInstanceId } from '@wordpress/compose';

export default function AccessRuleValueNotice( { label, notice, children }: { label: string; notice?: string; children: React.ReactNode } ) {
	// Unique per mount: the wizard renders one picker per active rule, and the block
	// inspector remounts its panel on every block selection.
	const noticeId = useInstanceId( AccessRuleValueNotice, 'newspack-access-rule-value-notice' );

	if ( ! notice ) {
		return <>{ children }</>;
	}
	return (
		<>
			{ /* Named, because a group with no accessible name is not one an assistive
			     technology enters, and the description hangs off entering it. */ }
			<div role="group" aria-label={ label } aria-describedby={ noticeId }>
				{ children }
			</div>
			<p id={ noticeId } role="note" className="newspack-access-rule-values-notice">
				{ notice }
			</p>
		</>
	);
}
