/**
 * LastUpdated
 *
 * Header-row timestamp display. Renders "Updated X minutes ago" or
 * absolute time depending on staleness. Renders nothing if no timestamp
 * is available (e.g., chrome is loaded before first cache hit).
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

export interface LastUpdatedProps {
	/** ISO 8601 timestamp of the most recent cache update, or null if unknown. */
	timestamp: string | null;
	/** Optional className for wrapper styling overrides. */
	className?: string;
}

const formatRelative = ( ts: Date, now: Date ): string => {
	// Math.floor (not Math.round) so a 30-second-old timestamp stays in
	// the "just now" branch instead of rounding up to "1 minute ago".
	// Same reasoning applies to the hour / day branches below.
	const diffMs = now.getTime() - ts.getTime();
	const diffMin = Math.floor( diffMs / ( 1000 * 60 ) );
	if ( diffMin < 1 ) {
		return __( 'Updated just now', 'newspack-plugin' );
	}
	if ( diffMin < 60 ) {
		return sprintf(
			/* translators: %d is number of minutes */
			__( 'Updated %d minutes ago', 'newspack-plugin' ),
			diffMin
		);
	}
	const diffHr = Math.floor( diffMin / 60 );
	if ( diffHr < 24 ) {
		return sprintf(
			/* translators: %d is number of hours */
			__( 'Updated %d hours ago', 'newspack-plugin' ),
			diffHr
		);
	}
	const diffDay = Math.floor( diffHr / 24 );
	return sprintf(
		/* translators: %d is number of days */
		__( 'Updated %d days ago', 'newspack-plugin' ),
		diffDay
	);
};

const LastUpdated = ( { timestamp, className }: LastUpdatedProps ) => {
	if ( ! timestamp ) {
		return null;
	}
	const ts = new Date( timestamp );
	if ( Number.isNaN( ts.getTime() ) ) {
		return null;
	}
	const now = new Date();
	const label = formatRelative( ts, now );
	const absolute = ts.toLocaleString();
	return (
		<span className={ className ?? 'newspack-insights__last-updated' } title={ absolute }>
			{ label }
		</span>
	);
};

export default LastUpdated;
