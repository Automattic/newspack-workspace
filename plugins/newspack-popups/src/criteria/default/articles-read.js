import { setMatchingAttribute } from '../utils';

setMatchingAttribute( 'articles_read', ras => {
	const views = ras.getUniqueActivitiesBy( 'article_view', 'post_id' );
	return views.filter( view => view.timestamp > Date.now() - 30 * 24 * 60 * 60 * 1000 ).length;
} );

setMatchingAttribute( 'articles_read_in_session', ras => {
	const views = ras.getUniqueActivitiesBy( 'article_view', 'post_id' );
	if ( ! views.length ) {
		return 0;
	}
	// Sort by descending timestamp.
	views.sort( ( a, b ) => b.timestamp - a.timestamp );
	// Bail if the most recent view is older than 30 minutes.
	if ( views[ 0 ].timestamp < Date.now() - 30 * 60 * 1000 ) {
		return 0;
	}
	// Increment until the gap between views is greater than 30 minutes.
	let i = 0;
	while ( i < views.length && views[ i + 1 ] && views[ i ].timestamp - views[ i + 1 ].timestamp < 30 * 60 * 1000 ) {
		i++;
	}
	return 1 + i; // Add 1 to account for the most recent view.
} );
