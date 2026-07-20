import { EVENTS, emit } from './events';

let allSegments: Record< string, NewspackRasSegment > = {};
let matchedSegment: string | null = null;

/**
 * Register segment definitions.
 *
 * @param segments Segments keyed by ID with { name, criteria, priority } values.
 */
function register( segments: Record< string, NewspackRasSegment > ): void {
	if ( ! segments || typeof segments !== 'object' ) {
		return;
	}
	const hadMatch = matchedSegment && ! allSegments[ matchedSegment ];
	allSegments = { ...allSegments, ...segments };
	if ( hadMatch && matchedSegment && allSegments[ matchedSegment ] ) {
		emit( EVENTS.segment, { segmentId: matchedSegment, segment: allSegments[ matchedSegment ], all: { ...allSegments } } );
	}
}

/**
 * Set the matched segment for the current reader.
 *
 * @param segmentId Segment ID or null to clear.
 *
 * @return Matched segment object or null.
 */
function setMatch( segmentId: string | number | null = null ): ( NewspackRasSegment & { id: string } ) | null {
	const normalizedId = segmentId !== null && segmentId !== undefined ? String( segmentId ) : null;
	if ( normalizedId === matchedSegment ) {
		return getMatch();
	}
	matchedSegment = normalizedId;
	const segment = matchedSegment ? allSegments[ matchedSegment ] || null : null;
	emit( EVENTS.segment, { segmentId: matchedSegment, segment, all: { ...allSegments } } );
	return getMatch();
}

/**
 * Get the matched segment.
 *
 * @return Matched segment object with id, or null.
 */
function getMatch(): ( NewspackRasSegment & { id: string } ) | null {
	if ( ! matchedSegment || ! allSegments[ matchedSegment ] ) {
		return null;
	}
	return { id: matchedSegment, ...allSegments[ matchedSegment ] };
}

/**
 * Get all registered segments.
 *
 * @return Segments keyed by ID.
 */
function getAll(): Record< string, NewspackRasSegment > {
	return { ...allSegments };
}

/**
 * Reset module state. For testing only.
 */
export function reset(): void {
	allSegments = {};
	matchedSegment = null;
}

export default {
	register,
	setMatch,
	getMatch,
	getAll,
};
