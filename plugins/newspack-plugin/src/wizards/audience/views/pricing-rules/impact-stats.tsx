/**
 * The impact preview's headline numbers: how much of the catalog a rule touches,
 * and how many existing subscribers it reaches.
 */

/**
 * WordPress dependencies
 */
import { __, _x, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Grid } from '../../../../../packages/components/src';
import StatTile, { type StatTileProps } from './stat-tile';
import { formatCount, finiteNumber } from './impact-format';

interface ImpactStatsProps {
	totalMatching: EngineCount;
	countLimited: boolean;
	// What the product count means differs by screen, so its caller supplies the line.
	productsDescription: string;
	audience?: RuleAudienceData;
	onViewProducts?: () => void;
}

type Figure = Pick< StatTileProps, 'value' | 'valueLabel' >;

const bounded = ( value: EngineCount, limited: boolean ): Figure => {
	const count = finiteNumber( value );
	if ( null === count ) {
		// Distinct from the locked rule's silence: there the figure does not apply,
		// here it never arrived.
		return { value: null, valueLabel: _x( 'Unavailable', 'a statistic the server did not return', 'newspack-plugin' ) };
	}
	const formatted = formatCount( count );
	if ( ! limited ) {
		return { value: formatted };
	}
	return {
		value: sprintf(
			/* translators: %s: a formatted count acting as a lower bound, e.g. "500". */
			__( '%s+', 'newspack-plugin' ),
			formatted
		),
		valueLabel: sprintf(
			/* translators: %s: a formatted count acting as a lower bound, e.g. "500". */
			__( 'At least %s', 'newspack-plugin' ),
			formatted
		),
	};
};

export default function ImpactStats( { totalMatching, countLimited, productsDescription, audience, onViewProducts }: ImpactStatsProps ) {
	const scope = audience?.supported ? audience : null;
	const isLocked = 'locked' === scope?.application;
	const lockedNote = __( 'Applies to new sign-ups only', 'newspack-plugin' );

	// Keyed on an untranslated id, so no locale can collide two tiles.
	const tiles: ( StatTileProps & { id: string } )[] = [
		{
			id: 'products',
			label: __( 'Products affected', 'newspack-plugin' ),
			...bounded( totalMatching, countLimited ),
			description: productsDescription,
			actionLabel: __( 'View Affected Products', 'newspack-plugin' ),
			onAction: onViewProducts,
		},
	];

	if ( scope ) {
		tiles.push(
			{
				id: 'scope',
				label: __( 'Subscribers in scope', 'newspack-plugin' ),
				...bounded( scope.total, scope.count_limited ),
				description: __( 'Renewing subscriptions on those products', 'newspack-plugin' ),
			},
			// The engine truncates oldest-first and the oldest are the ones a cohort
			// gate protects, so a capped split under-reports who is repriced.
			{
				id: 'caught',
				label: __( 'Eligible at renewal', 'newspack-plugin' ),
				...( isLocked ? { value: null } : bounded( scope.caught, scope.count_limited ) ),
				description: __( 'Repriced at their next renewal', 'newspack-plugin' ),
				secondary: isLocked ? lockedNote : undefined,
			},
			{
				id: 'protected',
				label: _x( 'Protected', 'subscribers who keep their original price', 'newspack-plugin' ),
				...( isLocked ? { value: null } : bounded( scope.protected, scope.count_limited ) ),
				description: __( 'Keep the price they signed up at', 'newspack-plugin' ),
				secondary: isLocked ? lockedNote : undefined,
			}
		);
	}

	return (
		<Grid className="newspack-pricing-rules__stats" columns={ tiles.length } gutter={ 16 } noMargin>
			{ tiles.map( ( { id, ...tile } ) => (
				<StatTile key={ id } { ...tile } />
			) ) }
		</Grid>
	);
}
