/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * The traffic split between the control and the challengers.
 *
 * @param {Object} props              Props.
 * @param {number} props.controlShare Percent of readers who see the control.
 * @param {Object} props.variants     Variants keyed by letter.
 */
const AllocationBar = ( { controlShare, variants } ) => {
	const challengers = Object.keys( variants ).filter( letter => letter !== 'a' );
	const challengerShare = challengers.length ? ( 100 - controlShare ) / challengers.length : 100 - controlShare;
	return (
		<div
			className="newspack-popups-ab-tests__allocation"
			role="img"
			aria-label={ sprintf(
				// Translators: %1$d is the control's share of readers, %2$d the challengers' share.
				__( '%1$d%% of readers see the control, %2$d%% see a challenger.', 'newspack-popups' ),
				controlShare,
				100 - controlShare
			) }
		>
			<span className="newspack-popups-ab-tests__allocation-segment is-control" style={ { width: `${ controlShare }%` } }>
				A { controlShare }%
			</span>
			{ challengers.map( letter => (
				<span
					key={ letter }
					className="newspack-popups-ab-tests__allocation-segment is-challenger"
					style={ { width: `${ challengerShare }%` } }
				>
					{ letter.toUpperCase() } { Math.round( challengerShare ) }%
				</span>
			) ) }
		</div>
	);
};

export default AllocationBar;
