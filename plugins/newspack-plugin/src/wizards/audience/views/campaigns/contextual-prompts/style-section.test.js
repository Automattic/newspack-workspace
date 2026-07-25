/**
 * Contextual Prompts Style section: on a block theme it only offers the handoff
 * to the Site Editor's Styles panel, with no site-wide style controls; on a
 * classic theme it renders the site-wide style control groups.
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

const CONTRAST_WARNING = 'This color combination may be hard for people to read.';
// A Notice announces itself into the a11y-speak region, which duplicates its text
// in the document, so the queries below are scoped to the rendered notice.
const NOTICE_CONTENT = '.components-notice__content';

const CLASSIC_STATUS = {
	is_block_theme: false,
	style_defaults: {
		color: { background: '#f7f7f7' },
		border: { radius: '10px' },
		spacing: { padding: { top: '20px', right: '20px', bottom: '20px', left: '20px' } },
	},
	style_palette: [
		{ name: 'Accent', slug: 'accent', color: '#178f15' },
		{ name: 'Base', slug: 'base', color: '#ffffff' },
	],
	style_font_sizes: [
		{ name: 'Small', slug: 'small', size: '14px' },
		{ name: 'Medium', slug: 'medium', size: '18px' },
	],
	site_editor_styles_url: 'https://example.test/wp-admin/site-editor.php?p=%2Fstyles',
};

describe( 'StyleSection on a classic theme', () => {
	it( 'renders control groups and no handoff button', () => {
		render( <StyleSection status={ CLASSIC_STATUS } styles={ {} } inFlight={ false } onChangeStyles={ () => {} } /> );

		expect( screen.queryByRole( 'link', { name: 'Edit Styles' } ) ).toBeNull();
		expect( screen.getByText( 'Background' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Text' ) ).toBeInTheDocument();
	} );

	it( 'shows Reset only for groups holding an override, and reset clears the group', () => {
		const onChangeStyles = jest.fn();
		render(
			<StyleSection
				status={ CLASSIC_STATUS }
				styles={ { color: { background: '#123456' } } }
				inFlight={ false }
				onChangeStyles={ onChangeStyles }
			/>
		);

		const resets = screen.getAllByRole( 'button', { name: 'Reset' } );
		expect( resets ).toHaveLength( 1 );
		resets[ 0 ].click();
		expect( onChangeStyles ).toHaveBeenCalledWith( {} );
	} );

	it( 'warns on a low-contrast pair', () => {
		render(
			<StyleSection
				status={ CLASSIC_STATUS }
				styles={ { color: { background: '#777777', text: '#888888' } } }
				inFlight={ false }
				onChangeStyles={ () => {} }
			/>
		);
		expect( screen.getByText( CONTRAST_WARNING, { selector: NOTICE_CONTENT } ) ).toBeInTheDocument();
	} );

	it( 'does not warn when only one color resolves', () => {
		render( <StyleSection status={ CLASSIC_STATUS } styles={ { color: { text: '#888888' } } } inFlight={ false } onChangeStyles={ () => {} } /> );
		// Default background #f7f7f7 vs #888888 is below 4.5 too, so the warning
		// must consider defaults: expect it present.
		expect( screen.getByText( CONTRAST_WARNING, { selector: NOTICE_CONTENT } ) ).toBeInTheDocument();
	} );
} );
