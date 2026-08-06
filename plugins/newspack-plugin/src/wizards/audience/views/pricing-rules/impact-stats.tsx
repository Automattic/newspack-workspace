/**
 * The impact preview's headline numbers: how much of the catalog a rule touches,
 * and how many existing subscribers it reaches.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies
 */
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

function Stat( { value, label, note }: { value: string; label: string; note?: string } ) {
	return (
		<VStack spacing={ 0 }>
			<span className="newspack-pricing-rules__stat-value">{ value }</span>
			<span className="newspack-pricing-rules__stat-label">{ label }</span>
			{ note && <span className="newspack-pricing-rules__stat-note">{ note }</span> }
		</VStack>
	);
}

export default function ImpactStats( { totalMatching, countLimited, audience }: ImpactStatsProps ) {
	const scope = audience?.supported ? audience : null;
	const isLocked = 'locked' === scope?.application;

	return (
		<div className="newspack-pricing-rules__stats">
			<HStack justify="flex-start" alignment="top" spacing={ 8 }>
				<Stat value={ bounded( totalMatching, countLimited ) } label={ __( 'Products affected', 'newspack-plugin' ) } />
				{ scope && <Stat value={ bounded( scope.total, scope.count_limited ) } label={ __( 'Subscribers in scope', 'newspack-plugin' ) } /> }
				{ scope && ! isLocked && (
					<Stat
						value={ formatCount( scope.caught ) }
						label={ __( 'Eligible at renewal', 'newspack-plugin' ) }
						note={ sprintf(
							/* translators: %s: how many subscribers in scope keep their current price. */
							_n( '%s protected', '%s protected', scope.protected, 'newspack-plugin' ),
							formatCount( scope.protected )
						) }
					/>
				) }
			</HStack>
			{ scope && isLocked && (
				<p className="newspack-pricing-rules__muted">
					{ __( 'Applies to new sign-ups only, so no existing subscriber is repriced.', 'newspack-plugin' ) }
				</p>
			) }
		</div>
	);
}
