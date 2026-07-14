/**
 * DataLagIndicator (NPPD-1618).
 *
 * Reporting-freshness context near the top of a tab body: when the displayed
 * figures were last finalized ("Data as of …"), plus — when the window includes
 * recent days the ad server hasn't cleared yet — a note that those recent
 * figures are estimated and may shift. Renders as a plain `@wordpress/components`
 * warning `Notice`, portaled to the top of the wizard body, and is deliberately
 * NOT dismissible: the content varies with the selected date range, so the
 * publisher should see it on every window.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { createPortal, useLayoutEffect, useState } from '@wordpress/element';
import { Notice } from '@wordpress/components';

export interface DataLagIndicatorProps {
	/** ISO YYYY-MM-DD of the most recent finalized data, or null/undefined. */
	dataAsOf?: string | null;
	hasEstimatedData?: boolean;
}

/**
 * A host node inserted as the first child of `.newspack-wizard__main` — inside
 * the wizard body but above (outside) `.newspack-wizard__content` — so the
 * callout reads as a page-level notice while still living in the routed tab (and
 * keeping its per-tab data). Returns null when the wizard chrome isn't present
 * (e.g. isolated tests), so the caller falls back to an inline render.
 */
const useWizardMainHost = (): HTMLElement | null => {
	const [ host, setHost ] = useState< HTMLElement | null >( null );
	useLayoutEffect( () => {
		const main = document.querySelector< HTMLElement >( '.newspack-wizard__main' );
		if ( ! main ) {
			return;
		}
		const el = document.createElement( 'div' );
		el.className = 'newspack-insights__page-notice';
		main.insertBefore( el, main.firstChild );
		setHost( el );
		return () => {
			el.remove();
		};
	}, [] );
	return host;
};

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
	// Called unconditionally (hooks rules) before the early return below.
	const host = useWizardMainHost();
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

	const callout = (
		<Notice status="warning" isDismissible={ false }>
			{ text }
		</Notice>
	);

	// Portal into the wizard body (above the routed content) when available;
	// otherwise render inline (isolated tests, or before the host is ready).
	return host ? createPortal( callout, host ) : callout;
};

export default DataLagIndicator;
