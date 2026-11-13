/**
 * External dependencies
 */
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import PromptSettingsDrawer from './index';

jest.mock( '@wordpress/api-fetch', () => jest.fn( () => Promise.resolve( [] ) ) );

const PROMPT = {
	id: 1,
	title: 'Tom &amp; Jerry',
	segments: [],
	categories: [],
	tags: [],
	campaign_groups: [],
	options: { frequency: 'daily', placement: 'inline' },
};

// The notice itself, not the a11y live region that speak() copies its text into.
const errorNotice = () => document.querySelector( '.newspack-campaigns__settings-error' );

describe( 'PromptSettingsDrawer', () => {
	beforeEach( () => {
		apiFetch.mockClear();
		window.newspackAudienceCampaigns = { overlay_placements: [], custom_placements: {}, overlay_sizes: [] };
	} );

	it( 'names the drawer after the prompt, with its entities decoded', () => {
		render( <PromptSettingsDrawer isOpen prompt={ PROMPT } onClose={ jest.fn() } updatePopup={ jest.fn() } /> );
		expect( screen.getByRole( 'heading', { name: 'Tom & Jerry' } ) ).not.toBeNull();
	} );

	it( 'saves and then closes', async () => {
		const onClose = jest.fn();
		const updatePopup = jest.fn( () => Promise.resolve() );
		render( <PromptSettingsDrawer isOpen prompt={ PROMPT } onClose={ onClose } updatePopup={ updatePopup } /> );
		fireEvent.change( screen.getByLabelText( 'Frequency' ), { target: { value: 'weekly' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		expect( updatePopup.mock.calls[ 0 ][ 0 ].options.frequency ).toBe( 'weekly' );
		await waitFor( () => expect( onClose ).toHaveBeenCalled() );
	} );

	it( 'stays open with the edit kept and shows why when the save is refused', async () => {
		const onClose = jest.fn();
		const updatePopup = jest.fn( () => Promise.reject( { message: 'A prompt in a running A/B test can&#8217;t use this placement.' } ) );
		render( <PromptSettingsDrawer isOpen prompt={ PROMPT } onClose={ onClose } updatePopup={ updatePopup } /> );
		fireEvent.change( screen.getByLabelText( 'Frequency' ), { target: { value: 'weekly' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		await waitFor( () => expect( errorNotice()?.textContent ).toContain( 'A prompt in a running A/B test can’t use this placement.' ) );
		expect( onClose ).not.toHaveBeenCalled();
		expect( screen.getByLabelText( 'Frequency' ).value ).toBe( 'weekly' );
	} );

	it( 'falls back to a generic message when the refusal has none', async () => {
		const updatePopup = jest.fn( () => Promise.reject( {} ) );
		render( <PromptSettingsDrawer isOpen prompt={ PROMPT } onClose={ jest.fn() } updatePopup={ updatePopup } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		await waitFor( () => expect( errorNotice()?.textContent ).toContain( 'The prompt could not be saved.' ) );
	} );

	it( 'closes after a retry succeeds', async () => {
		const onClose = jest.fn();
		const updatePopup = jest
			.fn()
			.mockReturnValueOnce( Promise.reject( { message: 'Refused.' } ) )
			.mockReturnValueOnce( Promise.resolve() );
		render( <PromptSettingsDrawer isOpen prompt={ PROMPT } onClose={ onClose } updatePopup={ updatePopup } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		await waitFor( () => expect( errorNotice() ).not.toBeNull() );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		await waitFor( () => expect( onClose ).toHaveBeenCalled() );
		expect( errorNotice() ).toBeNull();
	} );
} );
