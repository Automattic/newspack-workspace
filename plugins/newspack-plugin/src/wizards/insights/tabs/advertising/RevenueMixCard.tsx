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

/** Sum the `revenue` of every row whose `label` matches. */
const sumByLabel = ( rows: MetricRow[], label: string ): number =>
	rows.filter( row => String( row.label ) === label ).reduce( ( sum, row ) => sum + ( Number( row.revenue ) || 0 ), 0 );

const RevenueMixCard = ( { payload }: RevenueMixCardProps ) => {
	if ( ! payload || payload.hidden_in_v1 ) {
		return null;
	}

	const label = __( 'Revenue Mix', 'newspack-plugin' );
	const subtext = __( 'direct sales', 'newspack-plugin' );

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
	const programmaticPct = Math.round( ( programmatic / total ) * 100 );
	const restPct = Math.round( ( rest / total ) * 100 );

	const description =
		restPct > 0
			? sprintf(
					/* translators: %d: a whole-number percentage. */
					__( '%d%% from programmatic; house and other ads make up the rest.', 'newspack-plugin' ),
					programmaticPct
			  )
			: sprintf(
					/* translators: %d: a whole-number percentage. */
					__( '%d%% from programmatic.', 'newspack-plugin' ),
					programmaticPct
			  );

	return <MetricCard label={ label } value={ directShare } format="percent" secondary={ subtext } description={ description } />;
};

export default RevenueMixCard;
