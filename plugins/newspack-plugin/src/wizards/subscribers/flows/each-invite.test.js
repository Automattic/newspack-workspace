/**
 * Internal dependencies.
 */
import eachInvite from './each-invite';

const invites = [ { email: 'a@example.test' }, { email: 'b@example.test' }, { email: 'c@example.test' } ];

describe( 'eachInvite', () => {
	it( 'counts a batch that lands in part as done, with the failures reported', async () => {
		const write = jest.fn( email => ( 'b@example.test' === email ? Promise.reject( new Error( 'nope' ) ) : Promise.resolve() ) );

		await expect( eachInvite( invites, write ) ).resolves.toMatchObject( { done: 2, failed: 1 } );
		expect( write ).toHaveBeenCalledTimes( 3 );
	} );

	it( 'rejects with the first error when nothing lands, so the modal can show it', async () => {
		const write = jest.fn( () => Promise.reject( new Error( 'Group is full.' ) ) );

		await expect( eachInvite( invites, write ) ).rejects.toThrow( 'Group is full.' );
	} );

	// The endpoints rewrite the whole invitation list, so overlapping writes lose changes.
	it( 'starts each write only after the previous one settles', async () => {
		let release;
		const write = jest
			.fn()
			.mockImplementationOnce( () => new Promise( resolve => ( release = resolve ) ) )
			.mockResolvedValue();

		const batch = eachInvite( invites, write );
		await Promise.resolve();
		expect( write ).toHaveBeenCalledTimes( 1 );

		release();
		await batch;
		expect( write ).toHaveBeenCalledTimes( 3 );
	} );
} );
