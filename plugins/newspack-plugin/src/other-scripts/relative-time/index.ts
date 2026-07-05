/**
 * Relative Time script.
 *
 * Replaces post dates with relative "time ago" format on the frontend.
 * Runs client-side to bypass page caching (the PHP filter renders at cache time,
 * this script corrects stale relative dates).
 *
 * Reads: window.newspackRelativeTime.cutoff (seconds), window.newspackRelativeTime.locale
 */

( function () {
	const config = window.newspackRelativeTime;
	if ( ! config ) {
		return;
	}

	const { cutoff, locale } = config;

	/**
	 * Determine the best unit and value for Intl.RelativeTimeFormat.
	 *
	 * @param {number} diffSeconds Difference in seconds (positive = past).
	 * @return {{ value: number, unit: string }|null} Value and unit, or null if beyond cutoff.
	 */
	function getRelativeUnit( diffSeconds ) {
		if ( diffSeconds >= cutoff ) {
			return null;
		}

		const units = [
			{ unit: 'second', threshold: 60 },
			{ unit: 'minute', threshold: 3600 },
			{ unit: 'hour', threshold: 86400 },
			{ unit: 'day', threshold: 2592000 },
			{ unit: 'month', threshold: 31536000 },
			{ unit: 'year', threshold: Infinity },
		];

		for ( const { unit, threshold } of units ) {
			if ( diffSeconds < threshold ) {
				const divisors = { second: 1, minute: 60, hour: 3600, day: 86400, month: 2592000, year: 31536000 };
				return { value: -Math.round( diffSeconds / divisors[ unit ] ), unit };
			}
		}
		return null;
	}

	/**
	 * Format and replace date text in time elements.
	 */
	function updateDates() {
		const localeTag = locale.replaceAll( '_', '-' );

		let formatter;
		try {
			formatter = new Intl.RelativeTimeFormat( localeTag, { numeric: 'auto' } );
		} catch {
			return; // No Intl support.
		}

		const elements = document.querySelectorAll(
			'.wp-block-post-date time[datetime], time.entry-date[datetime], time.updated[datetime], .comment-meta time[datetime]'
		);
		const now = Date.now();

		elements.forEach( function ( el ) {
			const datetime = el.getAttribute( 'datetime' );
			if ( ! datetime ) {
				return;
			}

			// Show full date on hover for all date elements.
			if ( ! el.getAttribute( 'title' ) ) {
				el.setAttribute( 'title', new Date( datetime ).toLocaleString( localeTag ) );
			}

			// Skip block-theme modified dates (label is inside <time>). Classic-theme ones are fine.
			if ( el.closest( '[data-newspack-modified]' ) || el.closest( '.wp-block-post-date__modified-date' ) ) {
				return;
			}

			const diffSeconds = Math.round( ( now - new Date( datetime ).getTime() ) / 1000 );
			if ( diffSeconds < 0 ) {
				return;
			}

			const relative = getRelativeUnit( diffSeconds );
			if ( ! relative ) {
				return;
			}

			const formatted = formatter.format( relative.value, relative.unit );
			const anchor = el.querySelector( 'a' );
			if ( anchor ) {
				anchor.textContent = formatted;
			} else {
				el.textContent = formatted;
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', updateDates );
	} else {
		updateDates();
	}
} )();
