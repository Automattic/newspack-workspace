/**
 * The impact preview's headline numbers: how much of the catalog a rule touches,
 * and how many existing subscribers it reaches.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies
 */
import { Grid } from '../../../../../packages/components/src';
import StatTile, { type StatTileProps } from './stat-tile';
import { formatCount } from './impact-format';

interface ImpactStatsProps {
	totalMatching: number;
	countLimited: boolean;
	audience?: RuleAudienceData;
}

const bounded = ( value: number, limited: boolean ): string => {
	const formatted = formatCount( value );
	return limited
		? sprintf(
				/* translators: %s: a formatted count acting as a lower bound, e.g. "500". */
				__( '%s+', 'newspack-plugin' ),
				formatted
		  )
		: formatted;
};

export default function ImpactStats( { totalMatching, countLimited, audience }: ImpactStatsProps ) {
	const scope = audience?.supported ? audience : null;
	const isLocked = 'locked' === scope?.application;
	const lockedNote = __( 'Applies to new sign-ups only', 'newspack-plugin' );

	const tiles: StatTileProps[] = [
		{
			label: __( 'Products affected', 'newspack-plugin' ),
			value: bounded( totalMatching, countLimited ),
			description: __( 'Rules currently price these products', 'newspack-plugin' ),
		},
	];

	if ( scope ) {
		tiles.push(
			{
				label: __( 'Subscribers in scope', 'newspack-plugin' ),
				value: bounded( scope.total, scope.count_limited ),
				description: __( 'Renewing subscriptions on those products', 'newspack-plugin' ),
			},
			// The engine truncates oldest-first and the oldest are the ones a cohort
			// gate protects, so a capped split under-reports who is repriced.
			{
				label: __( 'Eligible at renewal', 'newspack-plugin' ),
				value: isLocked ? null : bounded( scope.caught, scope.count_limited ),
				description: __( 'Repriced at their next renewal', 'newspack-plugin' ),
				secondary: isLocked ? lockedNote : undefined,
			},
			{
				label: __( 'Protected', 'newspack-plugin' ),
				value: isLocked ? null : bounded( scope.protected, scope.count_limited ),
				description: __( 'Keep the price they signed up at', 'newspack-plugin' ),
				secondary: isLocked ? lockedNote : undefined,
			}
		);
	}

	// `noMargin` puts `margin: 0 !important` on the grid, so the section constraint
	// can only be centred from a wrapper.
	return (
		<HStack justify="center">
			<Grid className="newspack-pricing-rules__stats" columns={ tiles.length } gutter={ 16 } noMargin>
				{ tiles.map( tile => (
					<StatTile key={ tile.label } { ...tile } />
				) ) }
			</Grid>
		</HStack>
	);
}
