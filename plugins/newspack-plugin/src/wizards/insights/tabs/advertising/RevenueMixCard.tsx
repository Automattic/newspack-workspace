/**
 * RevenueMixCard (NPPD-1618).
 *
 * Renders the direct-vs-programmatic revenue split as a single prominent
 * scorecard (à la the Donors "Recurring Donor Retention" card): the direct
 * revenue share as the headline value, "direct sales" beneath it, and the
 * programmatic / house / other remainder described below. Reads the same
 * `direct_vs_programmatic` payload the breakdown pie used.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { MetricPayload, MetricRow } from '../components/metrics';
import MetricCard from '../components/MetricCard';

export interface RevenueMixCardProps {
	payload?: MetricPayload;
}

/** Normalize a row label for matching (trim + lowercase). */
const normLabel = ( value: unknown ): string =>
	String( value ?? '' )
		.trim()
		.toLowerCase();

/** Sum the `revenue` of every row whose `label` matches (case/space-insensitive). */
const sumByLabel = ( rows: MetricRow[], label: string ): number =>
	rows.filter( row => normLabel( row.label ) === label ).reduce( ( sum, row ) => sum + ( Number( row.revenue ) || 0 ), 0 );

const RevenueMixCard = ( { payload }: RevenueMixCardProps ) => {
	if ( ! payload || payload.hidden_in_v1 ) {
		return null;
	}

	const label = __( 'Revenue Mix', 'newspack-plugin' );
	const subtext = __( 'from direct sales', 'newspack-plugin' );

	if ( payload.overlay ) {
		return <MetricCard label={ label } overlay={ payload.overlay } />;
	}
	if ( payload.error ) {
		return <MetricCard label={ label } error={ payload.error } />;
	}

	const rows: MetricRow[] = Array.isArray( payload.rows ) ? payload.rows : [];
	const direct = sumByLabel( rows, 'direct' );
	const programmatic = sumByLabel( rows, 'programmatic' );
	const rest = sumByLabel( rows, 'house' ) + sumByLabel( rows, 'other' );
	const total = direct + programmatic + rest;

	if ( total <= 0 ) {
		return (
			<MetricCard
				label={ label }
				value={ 0 }
				format="percent"
				secondary={ subtext }
				description={ __( 'No ad revenue in this timeframe.', 'newspack-plugin' ) }
			/>
		);
	}

	const directShare = direct / total;
	const directPct = Math.round( directShare * 100 );
	const otherPct = 100 - directPct;

	// Definitional sentence (à la the Donors "Active Donors" card), with the
	// complement percentage inline. Edge cases for all-direct / all-programmatic
	// avoid an awkward "0% from programmatic".
	let description: string;
	if ( directPct >= 100 ) {
		description = __( 'All of your ad revenue comes from direct sales.', 'newspack-plugin' );
	} else if ( directPct <= 0 ) {
		description = __( 'All of your ad revenue comes from programmatic auctions.', 'newspack-plugin' );
	} else {
		description = sprintf(
			/* translators: %d: percentage of ad revenue from programmatic auctions. */
			__(
				'How your ad revenue splits between direct sales and programmatic auctions. The other %d%% comes from programmatic.',
				'newspack-plugin'
			),
			otherPct
		);
	}

	// Display the rounded share (directPct), not the raw fraction, so the headline
	// value, the all-direct/all-programmatic edge cases, and the inline complement
	// (otherPct) are always consistent — never "99.9%" next to "All … direct sales".
	return <MetricCard label={ label } value={ directPct / 100 } format="percent" secondary={ subtext } description={ description } />;
};

export default RevenueMixCard;
