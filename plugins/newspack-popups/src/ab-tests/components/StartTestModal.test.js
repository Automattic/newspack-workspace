/**
 * The Start A/B Test modal: what it sends to the start route, when it lets the
 * request go, and what it does when the request fails. Components are stubbed
 * down to native inputs.
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
import StartTestModal from './StartTestModal';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( './style.scss', () => ( {} ), { virtual: true } );
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
	SelectControl: ( { label, value, onChange, options, help } ) => (
		<div>
			<select aria-label={ label } value={ value } onChange={ e => onChange( e.target.value ) }>
				{ options.map( option => (
					<option key={ option.value } value={ option.value } disabled={ option.disabled }>
						{ option.label }
					</option>
				) ) }
			</select>
			<p>{ help }</p>
		</div>
	),
	RangeControl: ( { label, value, onChange } ) => (
		<input type="range" aria-label={ label } value={ value } onChange={ e => onChange( Number( e.target.value ) ) } />
	),
	__experimentalHStack: ( { children } ) => <div>{ children }</div>,
	__experimentalVStack: ( { children } ) => <div>{ children }</div>,
} ) );

const GOALS = { form_submission: 'Form submission', link_click: 'Link or button click', donation: 'Donation' };

const renderModal = props =>
	render(
		<StartTestModal
			promptId={ 7 }
			promptTitle="Spring drive"
			blockNames={ [ 'core/paragraph' ] }
			content="<p>Hi</p>"
			goals={ GOALS }
			intro="Intro."
			onCreated={ jest.fn() }
			onClose={ jest.fn() }
			{ ...props }
		/>
	);

const createButton = () => screen.getByRole( 'button', { name: 'Create Version B' } );

beforeEach( () => {
	apiFetch.mockReset();
} );

it( 'sends the name, goal and linked split for this prompt, then reports the result', async () => {
	const onCreated = jest.fn();
	apiFetch.mockResolvedValue( { test_id: 'spring-drive', version_b: { id: 8 } } );
	renderModal( { onCreated } );

	fireEvent.change( screen.getByLabelText( 'Test name' ), { target: { value: '  Spring drive copy  ' } } );
	fireEvent.change( screen.getByLabelText( 'Goal' ), { target: { value: 'donation' } } );
	fireEvent.change( screen.getByLabelText( 'Readers who see B' ), { target: { value: '30' } } );
	fireEvent.click( createButton() );

	await waitFor( () => expect( onCreated ).toHaveBeenCalledWith( { test_id: 'spring-drive', version_b: { id: 8 } } ) );
	expect( apiFetch ).toHaveBeenCalledWith( {
		path: '/newspack-popups/v1/ab-tests',
		method: 'POST',
		data: { prompt_id: 7, name: 'Spring drive copy', goal: 'donation', control_share: 70 },
	} );
} );

it( 'preselects the goal suggested by the prompt, and says where it came from', () => {
	renderModal( { blockNames: [ 'newspack-blocks/donate' ] } );
	expect( screen.getByLabelText( 'Goal' ).value ).toBe( 'donation' );
	expect( screen.getByText( /Suggested from the Donate block in this prompt\./ ) ).toBeTruthy();
} );

it( 'holds Create Version B until the test has a name and a goal', () => {
	renderModal();
	expect( createButton().disabled ).toBe( true );
	fireEvent.change( screen.getByLabelText( 'Goal' ), { target: { value: 'link_click' } } );
	expect( createButton().disabled ).toBe( false );
	fireEvent.change( screen.getByLabelText( 'Test name' ), { target: { value: '   ' } } );
	expect( createButton().disabled ).toBe( true );
} );

it( 'shows the route’s error and stays open for another try', async () => {
	const onCreated = jest.fn();
	apiFetch.mockRejectedValue( { message: 'This prompt is already in an A/B test.' } );
	renderModal( { onCreated, initialGoal: 'link_click' } );

	fireEvent.click( createButton() );

	expect( ( await screen.findByRole( 'alert' ) ).textContent ).toBe( 'This prompt is already in an A/B test.' );
	expect( onCreated ).not.toHaveBeenCalled();
	expect( createButton().disabled ).toBe( false );
} );

it( 'sends nothing when the step before the request fails', async () => {
	renderModal( { initialGoal: 'link_click', beforeCreate: () => Promise.reject( new Error( 'Save this prompt, then try again.' ) ) } );

	fireEvent.click( createButton() );

	expect( ( await screen.findByRole( 'alert' ) ).textContent ).toBe( 'Save this prompt, then try again.' );
	expect( apiFetch ).not.toHaveBeenCalled();
} );
