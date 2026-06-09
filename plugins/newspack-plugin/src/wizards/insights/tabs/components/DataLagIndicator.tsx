/**
 * DataLagIndicator (NPPD-1618).
 *
 * Reporting-freshness context near the top of a tab body: when the displayed
 * figures were last finalized ("Data as of …"), plus — when the window includes
 * recent days the ad server hasn't cleared yet — a note that those recent
 * figures are estimated and may shift. Renders via the shared {@see InfoCallout}
 * and is deliberately NOT dismissible: the content varies with the selected date
 * range, so the publisher should see it on every window.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import InfoCallout from './InfoCallout';

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

	if ( ! asOf ) {
		return null;
	}

	const estimatedFrom = formatIsoDate( estimatedWindowStartDate );

	return (
		<InfoCallout heading={ __( 'About this data', 'newspack-plugin' ) } dismissible={ false }>
			<p>
				{ sprintf(
					/* translators: %s: a date, e.g. "May 10, 2026". */
					__( 'Data as of %s.', 'newspack-plugin' ),
					asOf
				) }
			</p>
			{ hasEstimatedData && (
				<p>
					{ estimatedFrom
						? sprintf(
								/* translators: %s: a date, e.g. "May 3, 2026". */
								__( 'Figures from %s onward are estimated and may shift as Ad Exchange finalizes.', 'newspack-plugin' ),
								estimatedFrom
						  )
						: __( 'Recent figures are estimated and may shift as Ad Exchange finalizes.', 'newspack-plugin' ) }
				</p>
			) }
		</InfoCallout>
	);
};

export default DataLagIndicator;
