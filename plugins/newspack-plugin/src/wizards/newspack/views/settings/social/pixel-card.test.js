/**
 * External dependencies
 */
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

/**
 * Internal dependencies
 */
import PixelCard from './pixel-card';

const mockApiFetch = jest.fn();
let mockErrorMessage = null;
// Assigned on every render of the mocked hook, so a rejected request can push an
// error into state the way the real hook does — otherwise nothing re-renders.
let pushError = () => {};

jest.mock( '../../../../hooks/use-wizard-api-fetch', () => {
	const { useState } = require( '@wordpress/element' );
	return {
		useWizardApiFetch: () => {
			const [ error, setErrorMessage ] = useState( mockErrorMessage );
			pushError = setErrorMessage;
			return {
				wizardApiFetch: mockApiFetch,
				isFetching: false,
				errorMessage: error,
				setError: jest.fn(),
				resetError: jest.fn(),
			};
		},
	};
} );

const mockNotify = jest.fn();
jest.mock( './context', () => ( {
	useSocialCards: () => ( { notify: mockNotify } ),
	useErrorAnnouncement: () => {},
} ) );

const validate = value => ( /^[0-9]+$/.test( value.trim() ) ? null : 'Value may only contain numbers!' );

const renderCard = () =>
	render(
		<PixelCard
			title="Meta Pixel"
			description="Add the Meta pixel to your site."
			namespace="newspack-settings/social/pixels/meta"
			path="/newspack/v1/wizard/newspack-settings/social/meta_pixel"
			validate={ validate }
			renderHelp={ () => 'The Meta Pixel ID.' }
		/>
	);

/**
 * Make the mocked fetch resolve the initial GET with `stored`, and resolve
 * every later POST with the data it was handed.
 *
 * @param {Object} stored Stored pixel settings.
 */
const primeFetch = stored => {
	mockApiFetch.mockImplementation( ( opts, callbacks ) => {
		const response = opts.method === 'POST' ? opts.data : stored;
		callbacks?.onSuccess?.( response );
		return Promise.resolve( response );
	} );
};

/**
 * As `primeFetch`, but every POST rejects and surfaces `message` the way the
 * real hook does.
 *
 * @param {Object} stored  Stored pixel settings.
 * @param {string} message Error message the failed save reports.
 */
const primeFailingSave = ( stored, message ) => {
	mockApiFetch.mockImplementation( ( opts, callbacks ) => {
		if ( opts.method !== 'POST' ) {
			callbacks?.onSuccess?.( stored );
			return Promise.resolve( stored );
		}
		pushError( message );
		return Promise.reject( new Error( message ) );
	} );
};

beforeEach( () => {
	mockApiFetch.mockReset();
	mockNotify.mockReset();
	mockErrorMessage = null;
} );

describe( 'PixelCard', () => {
	it( 'gates the enable confirmation on a valid pixel ID', async () => {
		primeFetch( { active: false, pixel_id: '' } );
		renderCard();

		fireEvent.click( screen.getByRole( 'button', { name: 'Enable Meta Pixel' } ) );

		const confirm = screen.getByRole( 'button', { name: 'Enable' } );
		expect( confirm ).toBeDisabled();

		fireEvent.change( screen.getByLabelText( 'Pixel ID' ), { target: { value: 'abc' } } );
		expect( screen.getByRole( 'button', { name: 'Enable' } ) ).toBeDisabled();

		fireEvent.change( screen.getByLabelText( 'Pixel ID' ), { target: { value: '123' } } );
		expect( screen.getByRole( 'button', { name: 'Enable' } ) ).toBeEnabled();

		fireEvent.click( screen.getByRole( 'button', { name: 'Enable' } ) );

		await waitFor( () =>
			expect( mockApiFetch ).toHaveBeenCalledWith(
				expect.objectContaining( { method: 'POST', data: { active: true, pixel_id: '123' } } ),
				expect.anything()
			)
		);
		expect( mockNotify ).toHaveBeenCalledWith( 'Meta Pixel enabled.' );
	} );

	it( 'writes nothing when an enable is cancelled', async () => {
		primeFetch( { active: false, pixel_id: '' } );
		renderCard();

		fireEvent.click( screen.getByRole( 'button', { name: 'Enable Meta Pixel' } ) );
		fireEvent.change( screen.getByLabelText( 'Pixel ID' ), { target: { value: '123' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel editing Meta Pixel' } ) );

		expect( mockApiFetch ).not.toHaveBeenCalledWith( expect.objectContaining( { method: 'POST' } ), expect.anything() );
		expect( screen.getByRole( 'button', { name: 'Enable Meta Pixel' } ) ).toBeInTheDocument();
	} );

	it( 'keeps Update disabled until the value is dirty', async () => {
		primeFetch( { active: true, pixel_id: '123' } );
		renderCard();

		fireEvent.click( await screen.findByRole( 'button', { name: 'Edit Meta Pixel' } ) );

		expect( screen.getByRole( 'button', { name: 'Update' } ) ).toBeDisabled();

		fireEvent.change( screen.getByLabelText( 'Pixel ID' ), { target: { value: '456' } } );
		expect( screen.getByRole( 'button', { name: 'Update' } ) ).toBeEnabled();
	} );

	it( 'preserves the stored pixel ID when disabling', async () => {
		primeFetch( { active: true, pixel_id: '123' } );
		renderCard();

		fireEvent.click( await screen.findByRole( 'button', { name: 'Edit Meta Pixel' } ) );
		fireEvent.change( screen.getByLabelText( 'Pixel ID' ), { target: { value: '456' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Disable' } ) );

		await waitFor( () =>
			expect( mockApiFetch ).toHaveBeenCalledWith(
				expect.objectContaining( { method: 'POST', data: { active: false, pixel_id: '123' } } ),
				expect.anything()
			)
		);
		expect( mockNotify ).toHaveBeenCalledWith( 'Meta Pixel disabled.' );
	} );

	it( 'warns when the pixel is active with no ID', async () => {
		primeFetch( { active: true, pixel_id: '' } );
		renderCard();

		expect( await screen.findByText( 'Missing pixel ID' ) ).toBeInTheDocument();
	} );

	it( 'shows the validation message only once the field is touched', async () => {
		primeFetch( { active: false, pixel_id: '' } );
		renderCard();

		fireEvent.click( screen.getByRole( 'button', { name: 'Enable Meta Pixel' } ) );
		expect( screen.queryByText( 'Value may only contain numbers!' ) ).not.toBeInTheDocument();

		fireEvent.change( screen.getByLabelText( 'Pixel ID' ), { target: { value: 'abc' } } );
		expect( screen.getByText( 'Value may only contain numbers!' ) ).toBeInTheDocument();

		fireEvent.change( screen.getByLabelText( 'Pixel ID' ), { target: { value: '123' } } );
		expect( screen.queryByText( 'Value may only contain numbers!' ) ).not.toBeInTheDocument();
	} );

	it( 'reopens a clean form after a save', async () => {
		primeFetch( { active: true, pixel_id: '' } );
		renderCard();

		fireEvent.click( await screen.findByRole( 'button', { name: 'Edit Meta Pixel' } ) );
		fireEvent.change( screen.getByLabelText( 'Pixel ID' ), { target: { value: 'abc' } } );
		expect( screen.getByText( 'Value may only contain numbers!' ) ).toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: 'Disable' } ) );
		await waitFor( () => expect( mockNotify ).toHaveBeenCalledWith( 'Meta Pixel disabled.' ) );

		fireEvent.click( screen.getByRole( 'button', { name: 'Enable Meta Pixel' } ) );

		expect( screen.getByLabelText( 'Pixel ID' ) ).toHaveValue( '' );
		expect( screen.queryByText( 'Value may only contain numbers!' ) ).not.toBeInTheDocument();
	} );

	it( 'sends an empty string when disabling a row that stored no pixel ID', async () => {
		primeFetch( { active: true } );
		renderCard();

		fireEvent.click( await screen.findByRole( 'button', { name: 'Edit Meta Pixel' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Disable' } ) );

		await waitFor( () =>
			expect( mockApiFetch ).toHaveBeenCalledWith(
				expect.objectContaining( { method: 'POST', data: { active: false, pixel_id: '' } } ),
				expect.anything()
			)
		);
	} );

	// Characterises `save()`'s `.catch()` contract: a rejected save must not tear
	// down the form or fire a success notice. Not a guard for the error-display
	// work — the message and the Cancel label are both findable without it.
	it( 'swallows a failed save, leaving the draft and the open form intact', async () => {
		primeFailingSave( { active: false, pixel_id: '' }, 'Could not save.' );
		renderCard();

		fireEvent.click( screen.getByRole( 'button', { name: 'Enable Meta Pixel' } ) );
		fireEvent.change( screen.getByLabelText( 'Pixel ID' ), { target: { value: '123' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Enable' } ) );

		expect( await screen.findByText( 'Could not save.' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Pixel ID' ) ).toHaveValue( '123' );
		expect( screen.getByRole( 'button', { name: 'Cancel editing Meta Pixel' } ) ).toBeInTheDocument();
		expect( mockNotify ).not.toHaveBeenCalled();
	} );

	it( 'shows an API error in the header only while the card is closed', async () => {
		mockErrorMessage = 'Something went wrong.';
		primeFetch( { active: true, pixel_id: '123' } );
		renderCard();

		expect( await screen.findByText( 'Something went wrong.' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Add the Meta pixel to your site.' ) ).not.toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: 'Edit Meta Pixel' } ) );

		expect( screen.getByText( 'Add the Meta pixel to your site.' ) ).toBeInTheDocument();
		expect( screen.getAllByText( 'Something went wrong.' ) ).toHaveLength( 1 );
	} );
} );
