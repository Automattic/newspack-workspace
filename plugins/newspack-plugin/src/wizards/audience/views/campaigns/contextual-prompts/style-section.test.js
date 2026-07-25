/**
 * Contextual Prompts Style section: on a block theme it only offers the handoff
 * to the Site Editor's Styles panel, with no site-wide style controls.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import StyleSection from './style-section';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const HANDOFF_LINK = 'https://example.test/wp-admin/site-editor.php?handoff=1';
const RETURN_URL = 'https://example.test/wp-admin/admin.php?page=newspack-audience';

const BLOCK_THEME_STATUS = {
	is_block_theme: true,
	style_defaults: {},
	style_palette: [],
	style_font_sizes: [],
	site_editor_styles_url: 'https://example.test/wp-admin/site-editor.php?p=%2Fstyles',
};

describe( 'StyleSection on a block theme', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( { HandoffLink: HANDOFF_LINK } );
		delete window.location;
		window.location = { href: RETURN_URL };
	} );

	it( 'renders the handoff to the Styles panel and no controls', async () => {
		render( <StyleSection status={ BLOCK_THEME_STATUS } styles={ {} } inFlight={ false } onChangeStyles={ () => {} } /> );

		expect( screen.queryByText( 'Background' ) ).toBeNull();

		fireEvent.click( screen.getByRole( 'button', { name: 'Edit Styles' } ) );

		await waitFor( () => expect( window.location.href ).toBe( HANDOFF_LINK ) );
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/newspack/v1/handoff',
				method: 'POST',
				data: expect.objectContaining( { destinationUrl: BLOCK_THEME_STATUS.site_editor_styles_url } ),
			} )
		);
	} );
} );
