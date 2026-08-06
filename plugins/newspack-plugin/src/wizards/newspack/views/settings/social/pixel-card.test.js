/**
 * External dependencies
 */
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

/**
 * Internal dependencies
 */
import PixelCard from './pixel-card';

const mockApiFetch = jest.fn();

jest.mock( '../../../../hooks/use-wizard-api-fetch', () => ( {
	useWizardApiFetch: () => ( {
		wizardApiFetch: mockApiFetch,
		isFetching: false,
		errorMessage: null,
		setError: jest.fn(),
		resetError: jest.fn(),
	} ),
} ) );

const mockNotify = jest.fn();
jest.mock( './context', () => ( {
	useSocialCards: () => ( { notify: mockNotify } ),
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

beforeEach( () => {
	mockApiFetch.mockReset();
	mockNotify.mockReset();
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
} );
