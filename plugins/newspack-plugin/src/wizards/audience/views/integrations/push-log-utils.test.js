/**
 * Internal dependencies
 */
import {
	NEEDS_ATTENTION_VALUE,
	buildPushLogQuery,
	getAttemptLabel,
	getErrorKindLabel,
	getRetryNote,
	getStatusDisplay,
	getEmptyMessage,
} from './push-log-utils';

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

	it( 'names the retry a waiting row is waiting for, as its scheduled action does', () => {
		// The pending action is titled "… Retry 3 of 5", so the row it belongs
		// to has to count the same retry.
		const waiting = { status: 'retrying', attempts: 3, max_attempts: 6, retry: { is_pending: true } };
		expect( getAttemptLabel( waiting ) ).toBe( 'Waiting for retry 3 of 5' );
		expect( getAttemptLabel( { ...waiting, attempts: 1 } ) ).toBe( 'Waiting for retry 1 of 5' );
		expect( getAttemptLabel( { ...waiting, max_attempts: 1 } ) ).toBe( 'Waiting for retry 3' );
	} );

	it( 'leaves a stalled row to the retry note', () => {
		expect( getAttemptLabel( { status: 'retrying', attempts: 3, max_attempts: 6, retry: { is_pending: false } } ) ).toBe( '' );
	} );

	it( 'names the retry that is running right now', () => {
		const running = { status: 'retrying', attempts: 3, max_attempts: 6, retry: { is_pending: false, is_running: true } };
		expect( getAttemptLabel( running ) ).toBe( 'Running retry 3 of 5' );
		expect( getAttemptLabel( { ...running, max_attempts: 1 } ) ).toBe( 'Running retry 3' );
	} );
} );

describe( 'getErrorKindLabel', () => {
	it( 'reads a benign deletion as a contact that was already gone', () => {
		expect( getErrorKindLabel( { error_class: 'benign', operation: 'flag' } ) ).toBe( 'Already removed' );
		expect( getErrorKindLabel( { error_class: 'benign', operation: 'delete' } ) ).toBe( 'Already removed' );
	} );

	it( 'reads a benign update as a contact the provider already held', () => {
		expect( getErrorKindLabel( { error_class: 'benign', operation: 'upsert' } ) ).toBe( 'Already up to date' );
	} );

	it( 'names the other kinds, and falls back to the raw class', () => {
		expect( getErrorKindLabel( { error_class: 'transient', operation: 'upsert' } ) ).toBe( 'Temporary error' );
		expect( getErrorKindLabel( { error_class: 'something_new', operation: 'upsert' } ) ).toBe( 'something_new' );
	} );
} );

describe( 'getStatusDisplay', () => {
	it( 'draws a stalled retry as something to look at', () => {
		const stalled = getStatusDisplay( { status: 'retrying', retry: { action_id: 9, is_pending: false } } );
		expect( stalled ).toEqual( { label: 'Retrying', status: 'attention', intent: 'medium' } );
	} );

	it( 'leaves a retry that is still coming alone', () => {
		expect( getStatusDisplay( { status: 'retrying', retry: { action_id: 9, is_pending: true } } ).status ).toBe( 'progress' );
		expect( getStatusDisplay( { status: 'retrying', retry: { action_id: 9, is_pending: false, is_running: true } } ).status ).toBe( 'progress' );
		expect( getStatusDisplay( { status: 'success' } ).label ).toBe( 'Synced' );
	} );

	it( 'shows a status it does not know rather than nothing', () => {
		expect( getStatusDisplay( { status: 'something_new' } ) ).toEqual( { label: 'something_new', status: 'attention', intent: 'none' } );
	} );
} );

describe( 'getRetryNote', () => {
	it( 'flags a retrying row whose retry is gone', () => {
		expect( getRetryNote( { status: 'retrying', retry: { action_id: 9, is_pending: false, scheduled_at: null } } ) ).toBe(
			'Retry no longer scheduled'
		);
	} );

	it( 'stays quiet while the retry is pending or running, and on any other status', () => {
		expect( getRetryNote( { status: 'retrying', retry: { action_id: 9, is_pending: true, scheduled_at: '2026-09-10 10:02:30' } } ) ).toBe( '' );
		expect( getRetryNote( { status: 'retrying', retry: { action_id: 9, is_pending: false, is_running: true, scheduled_at: null } } ) ).toBe( '' );
		expect( getRetryNote( { status: 'failed', retry: null } ) ).toBe( '' );
	} );
} );

describe( 'getEmptyMessage', () => {
	it( 'reads an empty "needs attention" list as what the log holds, not as an all-clear', () => {
		// A push that sent only some fields still closes a failed one, so an
		// empty list does not prove every reader's data reached the provider.
		expect( getEmptyMessage( view( { filters: [ { field: 'needs_attention', operator: 'is', value: NEEDS_ATTENTION_VALUE } ] } ) ) ).toBe(
			'Nothing in the log needs attention.'
		);
	} );

	it( 'says a status or operation filter matched nothing, rather than that the log is empty', () => {
		expect( getEmptyMessage( view( { filters: [ { field: 'status', operator: 'is', value: 'failed' } ] } ) ) ).toBe(
			'No pushes match these filters.'
		);
		expect(
			getEmptyMessage( view( { search: 'reader@example.test', filters: [ { field: 'operation', operator: 'is', value: 'flag' } ] } ) )
		).toBe( 'No pushes match these filters.' );
	} );

	it( 'says a partial search matched no address start, rather than that the reader has no pushes', () => {
		// Anything short of a full address matches the start of one only.
		expect( getEmptyMessage( view( { search: 'smith' } ) ) ).toBe( 'No address in the log starts with “smith”.' );
	} );

	it( 'explains what the log holds when a reader is not found', () => {
		expect( getEmptyMessage( view( { search: 'reader@example.test' } ) ) ).toContain( 'No pushes recorded for this reader.' );
		// The server trims the search before deciding it is a full address.
		expect( getEmptyMessage( view( { search: ' reader@example.test ' } ) ) ).toContain( 'No pushes recorded for this reader.' );
	} );

	it( 'names the windows the site actually keeps, and falls back to the defaults', () => {
		expect( getEmptyMessage( view( { search: 'reader@example.test' } ), { success: 7, failed: 14 } ) ).toContain(
			'7 days for the ones that worked, 14 days for the ones that failed'
		);
		expect( getEmptyMessage( view( { search: 'reader@example.test' } ) ) ).toContain(
			'30 days for the ones that worked, 90 days for the ones that failed'
		);
	} );

	it( 'keeps a one-day window singular', () => {
		expect( getEmptyMessage( view( { search: 'reader@example.test' } ), { success: 1, failed: 90 } ) ).toContain(
			'1 day for the ones that worked, 90 days for the ones that failed'
		);
	} );

	it( 'says nothing was recorded yet otherwise', () => {
		expect( getEmptyMessage( view() ) ).toBe( 'No pushes recorded yet.' );
	} );
} );
