/**
 * SectionState (NPPD) — tab-local to Gates.
 *
 * Renders the right treatment for a collection metric's `state`:
 *   - 'error'     → a publisher-friendly error note (no internal codes)
 *   - 'empty'     → the section's own "no data yet" copy
 *   - 'populated' → the section's content (children)
 *
 * Keeps the funnel / distribution / performance sections declarative and
 * consistent in how they handle the three orchestrator states.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Icon, caution } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import type { GatesMetricState } from '../../api/gates';

export interface SectionStateProps {
	state: GatesMetricState;
	/** Copy shown when the query succeeded with no rows. */
	emptyMessage: string;
	children: React.ReactNode;
}

const SectionState = ( { state, emptyMessage, children }: SectionStateProps ) => {
	if ( state === 'error' ) {
		return (
			<p className="newspack-insights__section-error" role="alert">
				<Icon icon={ caution } size={ 20 } />
				<span>{ __( 'Unable to load this section. Newspack Manager may need attention.', 'newspack-plugin' ) }</span>
			</p>
		);
	}
	if ( state === 'empty' ) {
		return <p className="newspack-insights__section-empty">{ emptyMessage }</p>;
	}
	return <>{ children }</>;
};

export default SectionState;
