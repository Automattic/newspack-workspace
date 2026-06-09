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

const DataLagIndicator = ( { dataAsOf, hasEstimatedData }: DataLagIndicatorProps ) => {
	const asOf = formatIsoDate( dataAsOf );

	// Nothing to say only when there's neither an as-of date nor an estimate
	// warning. A window with estimated data but no as-of date still warns.
	if ( ! asOf && ! hasEstimatedData ) {
		return null;
	}

	let text: string;
	if ( asOf && hasEstimatedData ) {
		text = sprintf(
			/* translators: %s: a date, e.g. "May 10, 2026". */
			__( 'Data as of %s. Recent days are estimated and may shift until Google finalizes.', 'newspack-plugin' ),
			asOf
		);
	} else if ( asOf ) {
		text = sprintf(
			/* translators: %s: a date, e.g. "May 10, 2026". */
			__( 'Data as of %s.', 'newspack-plugin' ),
			asOf
		);
	} else {
		text = __( 'Recent days are estimated and may shift until Google finalizes.', 'newspack-plugin' );
	}

	return (
		<InfoCallout heading={ __( 'About this data', 'newspack-plugin' ) } dismissible={ false }>
			<p>{ text }</p>
		</InfoCallout>
	);
};

export default DataLagIndicator;
