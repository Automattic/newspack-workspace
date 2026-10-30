/**
 * External dependencies
 */
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';

/**
 * Internal dependencies
 */
import SegmentsPriority from './segments-priority';

// The live region repeats a spoken notice, and keeps it after the notice has gone.
const NOTICE = { selector: '.components-notice__content' };

const SEGMENTS = [
	{ id: 'a', name: 'Alpha' },
	{ id: 'b', name: 'Beta' },
	{ id: 'c', name: 'Gamma' },
];

const setup = wizardApiFetch => {
	window.newspackAudienceCampaigns = { api: '/api' };
	const props = { closeModal: jest.fn(), segments: SEGMENTS, setSegments: jest.fn(), wizardApiFetch };
	render( <SegmentsPriority { ...props } /> );
	return props;
};

const moveFirstDown = () => {
	fireEvent.click( screen.getAllByRole( 'button', { name: 'Move one position down' } )[ 0 ] );
	act( () => {
		jest.advanceTimersByTime( 200 );
	} );
};

describe( 'SegmentsPriority', () => {
	beforeEach( () => jest.useFakeTimers() );
	afterEach( () => jest.useRealTimers() );

	it( 'explains the order and lists titles only', () => {
		setup( jest.fn() );
		expect( screen.getByRole( 'dialog', { name: 'Segment Priority' } ) ).toBeInTheDocument();
		expect(
			screen.getByText(
				'Segments are checked in this order. When a reader matches more than one segment, only the first matching segment decides which targeted prompts they see.'
			)
		).toBeInTheDocument();
		expect( screen.getByRole( 'heading', { name: 'Alpha' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'checkbox' ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'More actions' } ) ).not.toBeInTheDocument();
	} );

	it( 'enables Save only while the order differs from the saved one', () => {
		setup( jest.fn() );
		const save = screen.getByRole( 'button', { name: 'Save' } );
		expect( save ).toHaveAttribute( 'aria-disabled', 'true' );

		moveFirstDown();
		expect( save ).not.toHaveAttribute( 'aria-disabled', 'true' );

		fireEvent.click( screen.getAllByRole( 'button', { name: 'Move one position up' } )[ 1 ] );
		act( () => {
			jest.advanceTimersByTime( 200 );
		} );
		expect( save ).toHaveAttribute( 'aria-disabled', 'true' );
	} );

	it( 'posts the new order, hands the reply on and closes', async () => {
		const reply = [ SEGMENTS[ 1 ], SEGMENTS[ 0 ], SEGMENTS[ 2 ] ];
		const wizardApiFetch = jest.fn().mockResolvedValue( reply );
		const { closeModal, setSegments } = setup( wizardApiFetch );

		moveFirstDown();
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		expect( wizardApiFetch ).toHaveBeenCalledWith( {
			path: '/api/segmentation-sort',
			method: 'POST',
			data: { segmentIds: [ 'b', 'a', 'c' ] },
			quiet: true,
		} );
		await waitFor( () => expect( closeModal ).toHaveBeenCalled() );
		expect( setSegments ).toHaveBeenCalledWith( reply );
	} );

	it( 'stays open on failure, shows the error, and clears it when retrying', async () => {
		let resolveRetry;
		const wizardApiFetch = jest
			.fn()
			.mockRejectedValueOnce( { message: 'Failed to sort.' } )
			.mockImplementationOnce( () => new Promise( resolve => ( resolveRetry = resolve ) ) );
		const { closeModal, setSegments } = setup( wizardApiFetch );

		moveFirstDown();
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		await screen.findByText( 'Failed to sort.', NOTICE );
		expect( closeModal ).not.toHaveBeenCalled();
		expect( setSegments ).not.toHaveBeenCalled();

		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		expect( screen.queryByText( 'Failed to sort.', NOTICE ) ).not.toBeInTheDocument();
		await act( async () => resolveRetry( SEGMENTS ) );
		expect( closeModal ).toHaveBeenCalled();
	} );

	it( 'falls back to a generic message', async () => {
		setup( jest.fn().mockRejectedValue( {} ) );
		moveFirstDown();
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		await screen.findByText( 'There was an error sorting segments. Please try again.', NOTICE );
	} );

	it( 'discards the order on Cancel', () => {
		const wizardApiFetch = jest.fn();
		const { closeModal, setSegments } = setup( wizardApiFetch );
		moveFirstDown();
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		expect( closeModal ).toHaveBeenCalled();
		expect( wizardApiFetch ).not.toHaveBeenCalled();
		expect( setSegments ).not.toHaveBeenCalled();
	} );
} );
