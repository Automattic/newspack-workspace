/**
 * Internal dependencies
 */
import { NEEDS_ATTENTION_VALUE, buildPushLogQuery, getAttemptLabel, getRetryNote, getEmptyMessage } from './push-log-utils';

const view = overrides => ( { page: 1, perPage: 25, sort: { field: 'updated_at', direction: 'desc' }, search: '', filters: [], ...overrides } );

describe( 'buildPushLogQuery', () => {
	it( 'asks for the first page, newest first, with nothing narrowed', () => {
		expect( buildPushLogQuery( view() ) ).toEqual( {
			page: 1,
			per_page: 25,
			order: 'DESC',
			search: undefined,
			status: undefined,
			operation: undefined,
			needs_attention: undefined,
		} );
	} );

	it( 'carries the search, the filters, the page and the order', () => {
		const query = buildPushLogQuery(
			view( {
				page: 3,
				perPage: 50,
				sort: { field: 'updated_at', direction: 'asc' },
				search: 'reader@example.test',
				filters: [
					{ field: 'status', operator: 'is', value: 'failed' },
					{ field: 'operation', operator: 'is', value: 'flag' },
					{ field: 'needs_attention', operator: 'is', value: NEEDS_ATTENTION_VALUE },
				],
			} )
		);

		expect( query ).toEqual( {
			page: 3,
			per_page: 50,
			order: 'ASC',
			search: 'reader@example.test',
			status: 'failed',
			operation: 'flag',
			needs_attention: true,
		} );
	} );
} );

describe( 'getAttemptLabel', () => {
	it( 'says nothing about a push that went through first time', () => {
		expect( getAttemptLabel( { attempts: 1, max_attempts: 6 } ) ).toBe( '' );
	} );

	it( 'counts retries, not attempts: the third attempt is the second retry', () => {
		expect( getAttemptLabel( { attempts: 3, max_attempts: 6 } ) ).toBe( 'Retry 2 of 5' );
	} );

	it( 'drops the ceiling when the row does not have a usable one', () => {
		expect( getAttemptLabel( { attempts: 3, max_attempts: 1 } ) ).toBe( 'Retry 2' );
	} );
} );

describe( 'getRetryNote', () => {
	it( 'flags a retrying row whose retry is gone', () => {
		expect( getRetryNote( { status: 'retrying', retry: { action_id: 9, is_pending: false, scheduled_at: null } } ) ).toBe(
			'Retry no longer scheduled'
		);
	} );

	it( 'stays quiet while the retry is pending, and on any other status', () => {
		expect( getRetryNote( { status: 'retrying', retry: { action_id: 9, is_pending: true, scheduled_at: '2026-09-10 10:02:30' } } ) ).toBe( '' );
		expect( getRetryNote( { status: 'failed', retry: null } ) ).toBe( '' );
	} );
} );

describe( 'getEmptyMessage', () => {
	it( 'reads an empty "needs attention" list as good news', () => {
		expect( getEmptyMessage( view( { filters: [ { field: 'needs_attention', operator: 'is', value: NEEDS_ATTENTION_VALUE } ] } ) ) ).toBe(
			'No sync problems.'
		);
	} );

	it( 'explains what the log holds when a reader is not found', () => {
		expect( getEmptyMessage( view( { search: 'reader@example.test' } ) ) ).toContain( 'No pushes recorded for this reader.' );
	} );

	it( 'says nothing was recorded yet otherwise', () => {
		expect( getEmptyMessage( view() ) ).toBe( 'No pushes recorded yet.' );
	} );
} );
