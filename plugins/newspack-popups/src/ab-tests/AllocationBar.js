/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatLetterList } from './utils';

/**
 * Each variant's share of readers: the control keeps its share and the
 * challengers split the rest evenly.
 *
 * @param {number} controlShare Percent of readers who see the control.
 * @param {Object} variants     Variants keyed by letter.
 * @return {Object} Percent per letter.
 */
export const getShares = ( controlShare, variants ) => {
	const challengers = Object.keys( variants ).filter( letter => letter !== 'a' );
	const challengerShare = challengers.length ? ( 100 - controlShare ) / challengers.length : 0;
	return Object.fromEntries( Object.keys( variants ).map( letter => [ letter, letter === 'a' ? controlShare : challengerShare ] ) );
};

/**
 * The traffic split between the control and the challengers.
 *
 * @param {Object} props              Props.
 * @param {number} props.controlShare Percent of readers who see the control.
 * @param {Object} props.variants     Variants keyed by letter.
 */
const AllocationBar = ( { controlShare, variants } ) => {
	const shares = getShares( controlShare, variants );
	return (
		<div
			className="newspack-popups-ab-tests__allocation"
			role="img"
			aria-label={ sprintf(
				// Translators: %s is a list of version letters with their share of readers, such as "A 60%, B 40%".
				__( 'Readers who see each version: %s.', 'newspack-popups' ),
				formatLetterList(
					Object.keys( variants ).map( letter =>
						sprintf(
							// Translators: %1$s is a version letter, %2$d its share of readers.
							__( '%1$s %2$d%%', 'newspack-popups' ),
							letter.toUpperCase(),
							Math.round( shares[ letter ] )
						)
					)
				)
			) }
		>
			{ Object.keys( variants ).map( letter => (
				<span
					key={ letter }
					className={ `newspack-popups-ab-tests__allocation-segment is-variant-${ letter }` }
					style={ { width: `${ shares[ letter ] }%` } }
				/>
			) ) }
		</div>
	);
};

export default AllocationBar;
