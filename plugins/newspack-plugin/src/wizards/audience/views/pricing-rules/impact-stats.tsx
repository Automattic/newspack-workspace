/**
 * The impact preview's headline numbers: how much of the catalog a rule touches,
 * and how many existing subscribers it reaches.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Grid } from '../../../../../packages/components/src';
import StatTile, { type StatTileProps } from './stat-tile';
import { formatCount } from './impact-format';

interface ImpactStatsProps {
	totalMatching: number;
	countLimited: boolean;
	// What the product count means differs by screen, so its caller supplies the line.
	productsDescription: string;
	audience?: RuleAudienceData;
	onViewProducts?: () => void;
	headingLevel?: StatTileProps[ 'headingLevel' ];
}

type Figure = Pick< StatTileProps, 'value' | 'valueLabel' >;

const bounded = ( value: number, limited: boolean ): Figure => {
	// The counts cross a REST boundary owned by the pricing engine; a missing one
	// would otherwise reach the tile as the string "NaN".
	if ( ! Number.isFinite( value ) ) {
		return { value: null };
	}
	const formatted = formatCount( value );
	if ( ! limited ) {
		return { value: formatted };
	}
	return {
		value: sprintf(
			/* translators: %s: a formatted count acting as a lower bound, e.g. "500". */
			__( '%s+', 'newspack-plugin' ),
			formatted
		),
		// Whether the "+" is spoken depends on punctuation verbosity, and it is the
		// only thing separating a floor from an exact count.
		valueLabel: sprintf(
			/* translators: %s: a formatted count acting as a lower bound, e.g. "500". */
			__( 'At least %s', 'newspack-plugin' ),
			formatted
		),
	};
};

export default function ImpactStats( {
	totalMatching,
	countLimited,
	productsDescription,
	audience,
	onViewProducts,
	headingLevel,
}: ImpactStatsProps ) {
	const scope = audience?.supported ? audience : null;
	const isLocked = 'locked' === scope?.application;
	const lockedNote = __( 'Applies to new sign-ups only', 'newspack-plugin' );

	const tiles: StatTileProps[] = [
		{
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
				label: __( 'Subscribers in scope', 'newspack-plugin' ),
				...bounded( scope.total, scope.count_limited ),
				description: __( 'Renewing subscriptions on those products', 'newspack-plugin' ),
			},
			// The engine truncates oldest-first and the oldest are the ones a cohort
			// gate protects, so a capped split under-reports who is repriced.
			{
				label: __( 'Eligible at renewal', 'newspack-plugin' ),
				...( isLocked ? { value: null } : bounded( scope.caught, scope.count_limited ) ),
				description: __( 'Repriced at their next renewal', 'newspack-plugin' ),
				secondary: isLocked ? lockedNote : undefined,
			},
			{
				label: __( 'Protected', 'newspack-plugin' ),
				...( isLocked ? { value: null } : bounded( scope.protected, scope.count_limited ) ),
				description: __( 'Keep the price they signed up at', 'newspack-plugin' ),
				secondary: isLocked ? lockedNote : undefined,
			}
		);
	}

	return (
		<Grid className="newspack-pricing-rules__stats" columns={ tiles.length } gutter={ 16 } noMargin>
			{ tiles.map( tile => (
				<StatTile key={ tile.label } { ...tile } headingLevel={ headingLevel } />
			) ) }
		</Grid>
	);
}
