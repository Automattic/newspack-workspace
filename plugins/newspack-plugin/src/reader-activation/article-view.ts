/**
 * Create useful data from the 'article_view' activity.
 */

export default function setupArticleViewsAggregates( ras ) {
	// Merge strategies for rehydration.

	/**
	 * articles_read — cumulative count. Take the higher value since both
	 * server and client increment independently and the true count can
	 * only be equal or greater than either.
	 */
	ras.store.register( 'articles_read', {
		merge: ( server, client ) => Math.max( server || 0, client || 0 ),
	} );

	/**
	 * paywall_hits — cumulative count, same reasoning as articles_read.
	 */
	ras.store.register( 'paywall_hits', {
		merge: ( server, client ) => Math.max( server || 0, client || 0 ),
	} );

	/**
	 * article_view_per_week — object keyed by week-boundary timestamps,
	 * each value is a { post_id: true } map. Deep-union both sources so
	 * reading history from different sessions/devices is combined.
	 */
	ras.store.register( 'article_view_per_week', {
		merge: ( server, client ) => {
			const merged = { ...( server || {} ) };
			for ( const period of Object.keys( client || {} ) ) {
				merged[ period ] = { ...merged[ period ], ...client[ period ] };
			}
			return merged;
		},
	} );

	/**
	 * article_view_per_month — same structure and reasoning as per_week,
	 * keyed by month-boundary timestamps.
	 */
	ras.store.register( 'article_view_per_month', {
		merge: ( server, client ) => {
			const merged = { ...( server || {} ) };
			for ( const period of Object.keys( client || {} ) ) {
				merged[ period ] = { ...merged[ period ], ...client[ period ] };
			}
			return merged;
		},
	} );

	/**
	 * favorite_categories — ranked array of category IDs. Union both
	 * sources with client-first ordering since the client has the most
	 * recent activity data. Server-only categories are appended to
	 * preserve cross-session engagement. Capped at 5.
	 */
	ras.store.register( 'favorite_categories', {
		merge: ( server, client ) => {
			const clientCats = client || [];
			const serverCats = server || [];
			const merged = [ ...clientCats ];
			for ( const cat of serverCats ) {
				if ( ! merged.includes( cat ) ) {
					merged.push( cat );
				}
			}
			return merged.slice( 0, 5 );
		},
	} );

	ras.on( 'activity', ( { detail: { action, data, timestamp } } ) => {
		if ( action !== 'article_view' ) {
			return;
		}
		const date = new Date( timestamp );
		// Reset time to 00:00:00:000.
		date.setHours( 0 );
		date.setMinutes( 0 );
		date.setSeconds( 0 );
		date.setMilliseconds( 0 );

		// Per week.
		const day = date.getDay();
		const daysToSaturday = 6 - day;
		date.setDate( date.getDate() + daysToSaturday );
		const week = date.getTime();
		const per_week = ras.store.get( 'article_view_per_week' ) || {};
		if ( ! per_week[ week ] ) {
			per_week[ week ] = {};
		}
		per_week[ week ][ data.post_id ] = true;
		ras.store.set( 'article_view_per_week', per_week );

		// Per month.
		date.setMonth( date.getMonth() + 1 );
		date.setDate( 1 );
		const month = date.getTime();
		const per_month = ras.store.get( 'article_view_per_month' ) || {};
		if ( ! per_month[ month ] ) {
			per_month[ month ] = {};
		}
		per_month[ month ][ data.post_id ] = true;
		ras.store.set( 'article_view_per_month', per_month );

		// articles_read — A cumulative count of articles the reader has read.
		const uniqueViews = ras.getUniqueActivitiesBy( 'article_view', 'post_id' );
		ras.store.set( 'articles_read', uniqueViews.length );

		// favorite_categories — A list of the reader's most-engaged content categories, ordered by frequency.
		const allActivities = ras.getActivities( 'article_view' );
		const catCounts = {};
		for ( const activity of allActivities ) {
			const cats = activity.data?.categories || [];
			for ( const cat of cats ) {
				catCounts[ cat ] = ( catCounts[ cat ] || 0 ) + 1;
			}
		}
		const topCategories = Object.entries( catCounts )
			.filter( ( [ , count ] ) => count >= 2 )
			.sort( ( a, b ) => b[ 1 ] - a[ 1 ] )
			.slice( 0, 5 )
			.map( ( [ id ] ) => Number( id ) );
		ras.store.set( 'favorite_categories', topCategories );
	} );
}
