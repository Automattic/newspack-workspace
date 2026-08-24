/**
 * The impact preview's headline numbers: how much of the catalog a rule touches,
 * and how many existing subscribers it reaches.
 */

/**
 * WordPress dependencies
 */
import { __, _x, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { Grid, StatCard } from '../../../../../packages/components/src';
import { formatCount, finiteNumber } from './impact-format';

interface ImpactStatsProps {
	totalMatching: EngineCount;
	countLimited: boolean;
	// What the product count means differs by screen, so its caller supplies the line.
	productsDescription: string;
	// The engine's two routes cap in opposite directions: the catalog union stops
	// early and under-counts, while impact_preview() counts its unwalked tail
	// unchecked, so a capped per-rule total is a ceiling. The consumer declares
	// which one its route documents.
	countBound?: 'lower' | 'upper';
	audience?: RuleAudienceData;
	onViewProducts?: () => void;
}

interface Tile {
	id: string;
	label: string;
	// Pre-formatted by the caller. Null renders the null glyph.
	value: string | null;
	// Spoken instead of the visible value, whose meaning may rest on punctuation.
	valueLabel?: string;
	description: string;
	note?: string;
	actionLabel?: string;
	onAction?: () => void;
}

type Figure = Pick< Tile, 'value' | 'valueLabel' >;

const bounded = ( value: EngineCount, limited: boolean, bound: 'lower' | 'upper' = 'lower' ): Figure => {
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
	if ( 'upper' === bound ) {
		return {
			value: sprintf(
				/* translators: %s: a formatted count acting as an upper bound, e.g. "500". */
				__( 'Up to %s', 'newspack-plugin' ),
				formatted
			),
		};
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

export default function ImpactStats( {
	totalMatching,
	countLimited,
	countBound = 'lower',
	productsDescription,
	audience,
	onViewProducts,
}: ImpactStatsProps ) {
	const scope = audience?.supported ? audience : null;
	const isLocked = 'locked' === scope?.application;
	const lockedNote = __( 'Applies to new sign-ups only.', 'newspack-plugin' );

	// Keyed on an untranslated id, so no locale can collide two tiles.
	const tiles: Tile[] = [
		{
			id: 'products',
			label: __( 'Products affected', 'newspack-plugin' ),
			...bounded( totalMatching, countLimited, countBound ),
			description: productsDescription,
			actionLabel: __( 'View Affected Products', 'newspack-plugin' ),
			onAction: onViewProducts,
		},
	];

	// The audience walk only ever omits, so its bound is a floor whatever the route.
	if ( scope ) {
		tiles.push(
			{
				id: 'scope',
				label: __( 'Subscribers in scope', 'newspack-plugin' ),
				...bounded( scope.total, scope.count_limited ),
				description: __( 'Renewing subscriptions on those products.', 'newspack-plugin' ),
			},
			// The engine truncates oldest-first and the oldest are the ones a cohort
			// gate protects, so a capped split under-reports who is repriced.
			{
				id: 'caught',
				label: __( 'Eligible at renewal', 'newspack-plugin' ),
				...( isLocked ? { value: null } : bounded( scope.caught, scope.count_limited ) ),
				description: __( 'Repriced at their next renewal.', 'newspack-plugin' ),
				note: isLocked ? lockedNote : undefined,
			},
			{
				id: 'protected',
				label: _x( 'Protected', 'subscribers who keep their original price', 'newspack-plugin' ),
				...( isLocked ? { value: null } : bounded( scope.protected, scope.count_limited ) ),
				description: __( 'Keep the price they signed up at.', 'newspack-plugin' ),
				note: isLocked ? lockedNote : undefined,
			}
		);
	}

	return (
		<Grid className="newspack-pricing-rules__stats" columns={ tiles.length } gutter={ 16 } noMargin>
			{ tiles.map( ( { id, label, value, valueLabel, description, note, actionLabel, onAction } ) => (
				<StatCard.Root key={ id }>
					<StatCard.Label>{ label }</StatCard.Label>
					<StatCard.Body>
						<StatCard.Value value={ value } valueLabel={ valueLabel } />
					</StatCard.Body>
					<StatCard.Footer>
						{ /* Its own element, or Footer would fold the reason and the description into one sentence. */ }
						{ note && <p className="newspack-stat-card__description">{ note }</p> }
						{ description }
						{ actionLabel && onAction && (
							// Opens a modal, so a button styled as a link rather than an anchor.
							<Button variant="link" className="newspack-stat-card__action" onClick={ onAction }>
								{ actionLabel }
							</Button>
						) }
					</StatCard.Footer>
				</StatCard.Root>
			) ) }
		</Grid>
	);
}
