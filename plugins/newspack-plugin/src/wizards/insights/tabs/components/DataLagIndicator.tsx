/**
 * DataLagIndicator (NPPD-1618).
 *
 * Small, muted informational line near the top of a tab body: when the
 * displayed figures were last finalized ("Data as of …"), plus — when the
 * window includes recent days GAM hasn't cleared yet — a footnote that those
 * recent figures are estimated and may shift. Informational context, not a
 * warning banner. Lives in shared `components/` for reuse by lagged-data tabs.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

export interface DataLagIndicatorProps {
	/** ISO YYYY-MM-DD of the most recent finalized data, or null/undefined. */
	dataAsOf?: string | null;
	hasEstimatedData?: boolean;
	/** ISO YYYY-MM-DD from which data is estimated, or null. */
	estimatedWindowStartDate?: string | null;
}

const dateFormatter = new Intl.DateTimeFormat( undefined, {
	month: 'short',
	day: 'numeric',
	year: 'numeric',
} );

/** Format an ISO `YYYY-MM-DD` string as a short date; falls back to the raw value. */
const formatIsoDate = ( iso?: string | null ): string => {
	if ( ! iso ) {
		return '';
	}
	const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec( iso );
	if ( ! match ) {
		return iso;
	}
	const date = new Date( Number( match[ 1 ] ), Number( match[ 2 ] ) - 1, Number( match[ 3 ] ) );
	return dateFormatter.format( date );
};

const DataLagIndicator = ( { dataAsOf, hasEstimatedData, estimatedWindowStartDate }: DataLagIndicatorProps ) => {
	const asOf = formatIsoDate( dataAsOf );

	if ( ! asOf && ! hasEstimatedData ) {
		return null;
	}

	const estimatedFrom = formatIsoDate( estimatedWindowStartDate );

	return (
		<div className="newspack-insights__data-lag" role="note">
			<span className="newspack-insights__data-lag-icon" aria-hidden="true">
				&#9432;
			</span>
			<div className="newspack-insights__data-lag-text">
				{ asOf && (
					<p className="newspack-insights__data-lag-asof">
						{ sprintf(
							/* translators: %s: a date, e.g. "May 10, 2026". */
							__( 'Data as of %s', 'newspack-plugin' ),
							asOf
						) }
					</p>
				) }
				{ hasEstimatedData && (
					<p className="newspack-insights__data-lag-footnote">
						{ estimatedFrom
							? sprintf(
									/* translators: %s: a date, e.g. "May 3, 2026". */
									__( 'Figures from %s onward are estimated and may shift as Ad Exchange finalizes.', 'newspack-plugin' ),
									estimatedFrom
							  )
							: __( 'Recent figures are estimated and may shift as Ad Exchange finalizes.', 'newspack-plugin' ) }
					</p>
				) }
			</div>
		</div>
	);
};

export default DataLagIndicator;
