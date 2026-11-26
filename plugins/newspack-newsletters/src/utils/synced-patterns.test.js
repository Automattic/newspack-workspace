// The email is rendered from each synced pattern's stored content, so the send
// button saves edited patterns before it renders or sends. These tests pin
// which records that touches, and that a failed save reaches the caller: a
// swallowed failure would let a send go out with the old pattern.
const mockSaveEditedEntityRecord = jest.fn();
let mockDirtyRecords = [];

jest.mock( '@wordpress/data', () => ( {
	select: () => ( { __experimentalGetDirtyEntityRecords: () => mockDirtyRecords } ),
	dispatch: () => ( { saveEditedEntityRecord: mockSaveEditedEntityRecord } ),
} ) );

import { saveEditedSyncedPatterns } from './synced-patterns';

describe( 'saveEditedSyncedPatterns', () => {
	beforeEach( () => {
		mockSaveEditedEntityRecord.mockReset();
		mockSaveEditedEntityRecord.mockResolvedValue( {} );
	} );

	it( 'saves edited synced patterns and leaves other edited records alone', async () => {
		mockDirtyRecords = [
			{ kind: 'postType', name: 'wp_block', key: 5 },
			{ kind: 'postType', name: 'newspack_nl_cpt', key: 6 },
			{ kind: 'root', name: 'site', key: undefined },
			{ kind: 'postType', name: 'wp_block', key: 9 },
		];

		await saveEditedSyncedPatterns();

		expect( mockSaveEditedEntityRecord.mock.calls.map( ( [ kind, name, key ] ) => [ kind, name, key ] ) ).toEqual( [
			[ 'postType', 'wp_block', 5 ],
			[ 'postType', 'wp_block', 9 ],
		] );
	} );

	it( 'rejects when a pattern fails to save', async () => {
		mockDirtyRecords = [ { kind: 'postType', name: 'wp_block', key: 5 } ];
		mockSaveEditedEntityRecord.mockRejectedValue( new Error( 'Sorry, you are not allowed to edit this post.' ) );

		await expect( saveEditedSyncedPatterns() ).rejects.toThrow( 'not allowed' );
		// Core only rejects a failed save when asked to.
		expect( mockSaveEditedEntityRecord ).toHaveBeenCalledWith( 'postType', 'wp_block', 5, { throwOnError: true } );
	} );
} );
