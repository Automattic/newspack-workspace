/**
 * ScopePill (NPPD-1649).
 *
 * Small inline badge rendered next to a table title to convey a uniform scope
 * (e.g. "Showing: United States" when every row shares one country). Used by the
 * Geographic section in place of a free-floating caption, so the scope reads as
 * tied to the title.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Badge } from '../../../../../packages/components/src';

export interface ScopePillProps {
	/** The uniform value to show (e.g. a country name). */
	label: string;
}

const ScopePill = ( { label }: ScopePillProps ) => (
	<span className="newspack-insights__scope-pill">
		<Badge
			text={ sprintf(
				/* translators: %s: the single value shared by every row (e.g. a country name). */
				__( 'Showing: %s', 'newspack-plugin' ),
				label
			) }
		/>
	</span>
);

export default ScopePill;
