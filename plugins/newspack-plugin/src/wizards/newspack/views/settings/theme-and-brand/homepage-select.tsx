/**
 * Newspack > Settings > Theme and Brand (Tab) > Homepage Select. Component used to select the homepage wp block pattern.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Grid, StyleCard } from '../../../../../../packages/components/src';

/**
 * Temporary loading cards to show while fetching data.
 */
const LOADING_CARDS = Array.from( { length: 12 }, Object );

export function HomepageSelect( {
	homepagePatternIndex,
	updateHomepagePattern,
	homepagePatterns,
	isFetching,
}: {
	homepagePatternIndex: number;
	updateHomepagePattern: ( a: number ) => void;
	homepagePatterns: HomepagePattern[];
	isFetching: boolean;
} ) {
	const items = isFetching && homepagePatterns.length === 0 ? LOADING_CARDS : homepagePatterns;

	return (
		<Grid columns={ 6 } gutter={ 16 }>
			{ items.map( ( pattern, i ) => (
				<StyleCard
					key={ i }
					image={ { __html: pattern.image } }
					imageType="html"
					isActive={ i === homepagePatternIndex }
					onClick={ () => {
						updateHomepagePattern( i );
					} }
					ariaLabel={ `${ __( 'Activate Layout', 'newspack-plugin' ) } ${ i + 1 }` }
				/>
			) ) }
		</Grid>
	);
}
