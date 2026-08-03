import { debug, getRawId } from './prompts';
import { getCriteria } from '../../criteria/utils';

const day = 1000 * 60 * 60 * 24;
export const periods = {
	day,
	week: day * 7,
	month: day * 30,
};

/**
 * Checks if the current page request is a segment or campaign preview.
 *
 * @param {string|null} queryString Query string to parse for view_as param. If not given, get from the current URL.
 *
 * @return {Object|null} View_as object or null.
 */
const parseViewAs = ( queryString = null ) => {
	if ( ! queryString ) {
		queryString = window.location.search;
	}
	const params = new URLSearchParams( queryString );
	if ( params.get( 'view_as' ) ) {
		const viewAs = params
			.get( 'view_as' )
			.split( ';' )
			.reduce( ( acc, item ) => {
				const parts = item.split( ':' );
				if ( 1 === parts.length ) {
					acc[ parts[ 0 ] ] = true;
				} else {
					acc[ parts[ 0 ] ] = parts[ 1 ];
				}
				return acc;
			}, {} );
		return viewAs;
	}

	return null;
};

/**
 * Checks if the current page request is a single prompt preview.
 *
 * @param {string|null} queryString Query string to parse for pid param. If not given, get from the current URL.
 *
 * @return {number|null} Prompt ID, or null.
 */
export const getPreviewedPromptId = ( queryString = null ) => {
	if ( ! queryString ) {
		queryString = window.location.search;
	}
	const params = new URLSearchParams( queryString );
	if ( params.get( 'pid' ) ) {
		return parseInt( params.get( 'pid' ) );
	}
	return null;
};

/**
 * Whether the reader matches the segment criteria.
 *
 * @param {Object} segmentCriteria Segment criteria.
 *
 * @return {boolean} True if the reader matches all of the segment's criteria, false if not.
 */
const match = segmentCriteria => {
	for ( const item of segmentCriteria ) {
		const criteria = getCriteria( item.criteria_id );
		if ( ! criteria ) {
			continue;
		}
		if ( ! criteria.matches( item ) ) {
			return false;
		}
	}
	return true;
};

/**
 * Get the reader's highest-priority segment match, or the segment to preview.
 *
 * @param {Object}      segments     Segments.
 * @param {string|null} viewAsString Optional, for testing. A query string with viewAs params for previewing a segment.
 *
 * @return {string|null} Segment ID, or null.
 */
export const getBestPrioritySegment = ( segments, viewAsString = null ) => {
	// If previewing as a specific segment.
	const viewAs = parseViewAs( viewAsString );
	if ( viewAs?.segment ) {
		return viewAs.segment;
	}

	const matchingSegments = [];
	for ( const segmentId in segments ) {
		if ( match( segments[ segmentId ].criteria ) ) {
			matchingSegments.push( {
				id: segmentId,
				priority: segments[ segmentId ].priority,
			} );
		}
	}

	if ( ! matchingSegments.length ) {
		return null;
	}

	matchingSegments.sort( ( a, b ) => a.priority - b.priority );

	return matchingSegments[ 0 ].id;
};

/**
 * Check the reader's activity against a given prompt's assigned segments.
 *
 * @param {HTMLElement}  prompt          HTML element of the prompt being checked.
 * @param {string}       matchingSegment ID of the reader's highest-priority matching segment.
 * @param {Object}       ras             Reader Data Library object.
 * @param {null|boolean} override        If true or false, force the value.
 * @return {boolean} True if the prompt should be displayed, false if not.
 */
export const shouldPromptBeDisplayed = ( prompt, matchingSegment, ras, override = null ) => {
	const id = prompt.getAttribute( 'id' );
	const suppression = [];
	const debugInfo = {
		element: prompt,
	};

	const shouldDisplay = () => {
		// By override.
		if ( true === override || false === override ) {
			debugInfo.override = true;
			if ( ! override ) {
				suppression.push( 'Prompt suppressed by override.' );
			}
			return override;
		}

		// If RAS is not available, the prompt should not be displayed.
		if ( ! ras ) {
			suppression.push( 'Prompt not displayed because RAS is not available.' );
			return false;
		}

		// eslint-disable-next-line @wordpress/no-unused-vars-before-return
		const [ start, between, max, reset ] = prompt.getAttribute( 'data-frequency' ).split( ',' );
		const pageviews = ras.store.get( 'pageviews' );
		if ( pageviews[ reset ] ) {
			const views = pageviews[ reset ].count || 0;

			// If reader hasn't amassed enough pageviews yet.
			if ( views <= parseInt( start ) ) {
				suppression.push( `Prompt displayed starting at pageview ${ parseInt( start ) + 1 }. Reader has only ${ views } pageviews.` );
				return false;
			}

			// If not displaying every pageview.
			if ( 0 < between ) {
				const viewsAfterStart = Math.max( 0, views - ( parseInt( start ) + 1 ) );
				if ( 0 < viewsAfterStart % ( parseInt( between ) + 1 ) ) {
					suppression.push( `Prompt displayed once every ${ parseInt( between ) + 1 } pageviews.` );
					return false;
				}
			}

			// If there's a max frequency.
			const promptId = getRawId( id );
			const seenEvents = ( ras.getActivities( 'prompt_seen' ) || [] ).filter( activity => {
				return activity.data?.prompt_id === promptId && periods[ reset ] > Date.now() - activity.timestamp;
			} );
			if ( 0 < parseInt( max ) && seenEvents.length >= parseInt( max ) ) {
				suppression.push( `Prompt already displayed the max of ${ max } times.` );
				return false;
			}
		}

		// Handle UTM suppression.
		const suppressByUTM = prompt.getAttribute( 'data-suppression' );
		if ( suppressByUTM ) {
			const suppressionValues = ras.store.get( 'utm_source' ) || [];
			const params = new URLSearchParams( window.location.search );
			const currentUTM = params.get( 'utm_source' );
			let suppressedByUTM = false;
			if ( -1 < suppressionValues.indexOf( suppressByUTM ) ) {
				suppressedByUTM = true;
			}
			if ( ! suppressedByUTM && suppressByUTM === currentUTM ) {
				suppressedByUTM = true;
				ras.store.set( 'utm_source', [ ...suppressionValues, currentUTM ] );
			}
			if ( suppressedByUTM ) {
				suppression.push( `Prompt suppressed by utm_source=${ suppressByUTM }.` );
				return false;
			}
		}

		// By assigned segments.
		const assignedSegments = prompt.getAttribute( 'data-segments' ) ? prompt.getAttribute( 'data-segments' ).split( ',' ) : null;
		if ( assignedSegments && 0 > assignedSegments.indexOf( matchingSegment ) ) {
			suppression.push( 'Reader does not match prompt’s assigned segments.' );
			return false;
		}

		return true;
	};

	const display = shouldDisplay();
	debugInfo.displayed = display;
	if ( 0 < suppression.length ) {
		debugInfo.suppression = suppression;
	}
	debug( id, debugInfo );

	return display;
};

/**
 * Get an override value to supersede segmentation and frequency controls. Possible values:
 * - true - The prompt will always be displayed.
 * - false - The prompt will never be displaeyd.
 * - null (default) - Let segmentation and frequency controls determine if the prompt should be displayed.
 *
 * @param {number}      promptId         ID of the prompt to check.
 * @param {boolean}     isOverlay        Whether the prompt is an overlay prompt.
 * @param {boolean}     overlayDisplayed Whether another overlay prompt has already been displayed.
 * @param {string|null} pidString        Optional, for testing. A query string containing a PID param.
 *
 * @return {boolean|null} The override value to pass to the shouldPromptBeDisplayed function.
 */
export const getOverride = ( promptId, isOverlay = false, overlayDisplayed = false, pidString = null ) => {
	// If previewing a single prompt, it should always be displayed.
	if ( promptId === getPreviewedPromptId( pidString ) ) {
		return true;
	}

	// If an overlay and another overlay has already been displayed, it should not be displaeyd.
	if ( isOverlay && overlayDisplayed ) {
		return false;
	}

	// Default behavior lets frequency/segmentation determine whether it should be dipslayed.
	return null;
};
