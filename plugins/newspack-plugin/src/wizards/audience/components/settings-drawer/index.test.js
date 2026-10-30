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

describe( 'PromptSettingsDrawer', () => {
	beforeEach( () => {
		apiFetch.mockClear();
		window.newspackAudienceCampaigns = { overlay_placements: [], custom_placements: {}, overlay_sizes: [] };
	} );

	it( 'renders nothing while closed', () => {
		render( <PromptSettingsDrawer isOpen={ false } prompt={ PROMPT } onClose={ jest.fn() } updatePopup={ jest.fn() } /> );
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
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
} );
