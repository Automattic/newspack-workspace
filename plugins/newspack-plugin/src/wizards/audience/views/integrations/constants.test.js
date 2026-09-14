/**
 * Internal dependencies
 */
import { STATUS_MAP } from './constants';
import { statusGlyph } from '../../../../../packages/components/src/status-indicator';

/**
 * The activity log's Status column offers these as separate filters, so two statuses
 * drawing the same mark makes the column unreadable at a glance: the reader can filter
 * to Canceled and Failed separately but cannot tell them apart in the results. The same
 * holds for the intents, which still badge a single status in the detail modal.
 */
describe( 'STATUS_MAP', () => {
	it( 'labels every status the log can report', () => {
		expect( Object.keys( STATUS_MAP ).sort() ).toEqual( [ 'canceled', 'complete', 'failed', 'in-progress', 'pending' ] );
		Object.values( STATUS_MAP ).forEach( ( { label } ) => expect( label ).toBeTruthy() );
	} );

	it( 'names every status the log can draw', () => {
		Object.values( STATUS_MAP ).forEach( ( { status } ) => expect( status ).toBeTruthy() );
	} );

	it( 'gives no two statuses the same mark', () => {
		const glyphs = Object.values( STATUS_MAP ).map( ( { status } ) => statusGlyph( status ) );
		expect( new Set( glyphs ).size ).toBe( glyphs.length );
	} );

	it( 'gives no two statuses the same intent', () => {
		const intents = Object.values( STATUS_MAP ).map( ( { intent } ) => intent );
		expect( new Set( intents ).size ).toBe( intents.length );
	} );

	it( 'separates a cancelled job from a failed one', () => {
		// Cancelling is a deliberate stop with nothing to act on; failure is the one
		// state in this column that asks the reader to do something.
		expect( statusGlyph( STATUS_MAP.failed.status ) ).not.toBe( statusGlyph( STATUS_MAP.canceled.status ) );
		expect( STATUS_MAP.failed.intent ).toBe( 'high' );
		expect( STATUS_MAP.canceled.intent ).toBe( 'none' );
	} );

	it( 'reads a queued job as worth noticing and a running one as context', () => {
		expect( STATUS_MAP.pending.intent ).toBe( 'low' );
		expect( STATUS_MAP[ 'in-progress' ].intent ).toBe( 'informational' );
	} );
} );
