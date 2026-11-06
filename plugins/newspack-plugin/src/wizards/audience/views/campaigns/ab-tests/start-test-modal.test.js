/**
 * The tab's Start A/B Test modal: what it sends to the start route and the
 * share limits it gives the two ranges. It is a copy of the editor's modal in
 * newspack-popups, so these cases are what keeps the two in step. Components
 * are stubbed down to native inputs.
 */

/**
 * External dependencies
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import StartTestModal from './start-test-modal';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/ui', () => ( {
	Stack: ( { children } ) => <div>{ children }</div>,
} ) );
jest.mock( '@wordpress/components', () => ( {
	Modal: ( { title, children } ) => (
		<div role="dialog" aria-label={ title }>
			{ children }
		</div>
	),
	Notice: ( { children } ) => <div role="alert">{ children }</div>,
	Button: ( { children, onClick, disabled } ) => (
		<button onClick={ onClick } disabled={ disabled }>
			{ children }
		</button>
	),
	TextControl: ( { label, value, onChange } ) => <input aria-label={ label } value={ value } onChange={ e => onChange( e.target.value ) } />,
	SelectControl: ( { label, value, onChange, options } ) => (
		<select aria-label={ label } value={ value } onChange={ e => onChange( e.target.value ) }>
			{ options.map( option => (
				<option key={ option.value } value={ option.value } disabled={ option.disabled }>
					{ option.label }
				</option>
			) ) }
		</select>
	),
	RangeControl: ( { label, value, min, max, onChange } ) => (
		<input type="range" aria-label={ label } value={ value } min={ min } max={ max } onChange={ e => onChange( Number( e.target.value ) ) } />
	),
} ) );

const GOALS = { form_submission: 'Form submission', donation: 'Donation' };

const renderModal = props =>
	render(
		<StartTestModal
			promptId={ 12 }
			testName="Homepage banner"
			goals={ GOALS }
			intro="Intro"
			onCreated={ jest.fn() }
			onClose={ jest.fn() }
			{ ...props }
		/>
	);

describe( 'StartTestModal', () => {
	beforeEach( () => apiFetch.mockReset() );

	it( 'sends the prompt, trimmed name, goal and A’s share', async () => {
		apiFetch.mockResolvedValue( { test_id: 'homepage-banner' } );
		const onCreated = jest.fn();
		renderModal( { initialGoal: 'donation', initialShare: 60, testName: '  Homepage banner  ', onCreated } );

		fireEvent.click( screen.getByRole( 'button', { name: 'Create Version B' } ) );

		await waitFor( () => expect( onCreated ).toHaveBeenCalledWith( { test_id: 'homepage-banner' } ) );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/newspack-popups/v1/ab-tests',
			method: 'POST',
			data: { prompt_id: 12, name: 'Homepage banner', goal: 'donation', control_share: 60 },
		} );
	} );

	it( 'needs a name and a goal before it sends anything', () => {
		renderModal( { testName: '' } );
		expect( screen.getByRole( 'button', { name: 'Create Version B' } ) ).toBeDisabled();

		fireEvent.change( screen.getByLabelText( 'Test name' ), { target: { value: 'Banner' } } );
		expect( screen.getByRole( 'button', { name: 'Create Version B' } ) ).toBeDisabled();

		fireEvent.change( screen.getByLabelText( 'Goal' ), { target: { value: 'donation' } } );
		expect( screen.getByRole( 'button', { name: 'Create Version B' } ) ).toBeEnabled();
	} );

	it( 'keeps both shares between 20 and 80, adding up to 100', () => {
		renderModal( { initialGoal: 'donation' } );
		const a = screen.getByLabelText( 'Readers who see A' );
		const b = screen.getByLabelText( 'Readers who see B' );
		[ a, b ].forEach( range => {
			expect( range ).toHaveAttribute( 'min', '20' );
			expect( range ).toHaveAttribute( 'max', '80' );
		} );

		fireEvent.change( b, { target: { value: '30' } } );
		expect( a ).toHaveValue( '70' );
	} );

	it( 'shows the route’s error and lets the person try again', async () => {
		apiFetch.mockRejectedValue( { message: 'That prompt is already in a test.' } );
		renderModal( { initialGoal: 'donation' } );

		fireEvent.click( screen.getByRole( 'button', { name: 'Create Version B' } ) );

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent( 'That prompt is already in a test.' );
		expect( screen.getByRole( 'button', { name: 'Create Version B' } ) ).toBeEnabled();
	} );
} );
