/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * A rule whose taxonomy no longer exists on this site. Shows the stored term IDs.
 */
export default function UnavailableRule( { slug, value, showHelp = true }: { slug: string; value?: GateContentRuleValue; showHelp?: boolean } ) {
	const ids = Array.isArray( value ) ? value.join( ', ' ) : '';
	return (
		<>
			<p>
				{ sprintf(
					// translators: 1: rule slug, 2: comma-separated term IDs.
					__( '%1$s (no longer on this site): %2$s', 'newspack-plugin' ),
					slug,
					ids
				) }
			</p>
			{ showHelp && <p className="description">{ __( 'Remove this rule to publish the gate or change its rules.', 'newspack-plugin' ) }</p> }
		</>
	);
}
