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

// Every group renders its Reset beside its own heading, so a group can be reset by
// name while other groups also hold an override.
const groupReset = label => screen.getByRole( 'heading', { name: label, level: 3 } ).parentElement.querySelector( 'button' );

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
		expect( screen.getByRole( 'button', { name: 'Text' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Background' } ) ).toBeInTheDocument();
	} );

	it( 'opens a color palette in a popover from a color row', () => {
		render( <StyleSection status={ CLASSIC_STATUS } styles={ {} } inFlight={ false } onChangeStyles={ () => {} } /> );

		expect( screen.queryByRole( 'listbox', { name: 'Text' } ) ).toBeNull();

		fireEvent.click( screen.getByRole( 'button', { name: 'Text' } ) );

		expect( screen.getByRole( 'listbox', { name: 'Text' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'option', { name: 'Accent' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'listbox', { name: 'Background' } ) ).toBeNull();
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

	it( 'prunes the parent node when a nested override is reset', () => {
		const onChangeStyles = jest.fn();
		render(
			<StyleSection
				status={ CLASSIC_STATUS }
				styles={ { spacing: { padding: { top: '1px' } } } }
				inFlight={ false }
				onChangeStyles={ onChangeStyles }
			/>
		);

		expect( screen.getAllByRole( 'button', { name: 'Reset' } ) ).toHaveLength( 1 );
		groupReset( 'Padding' ).click();
		expect( onChangeStyles ).toHaveBeenCalledWith( {} );
	} );

	it( 'keeps the border radius when the border group is reset', () => {
		const onChangeStyles = jest.fn();
		render(
			<StyleSection
				status={ CLASSIC_STATUS }
				styles={ { border: { radius: '4px', width: '1px' } } }
				inFlight={ false }
				onChangeStyles={ onChangeStyles }
			/>
		);

		groupReset( 'Border' ).click();
		expect( onChangeStyles ).toHaveBeenCalledWith( { border: { radius: '4px' } } );
	} );

	it( 'disables the controls that take a disabled prop while a save is in flight', () => {
		render(
			<StyleSection status={ CLASSIC_STATUS } styles={ { color: { background: '#123456' } } } inFlight={ true } onChangeStyles={ () => {} } />
		);

		// The pickers rely on the inert wrapper, which jsdom does not honor, so this
		// covers the controls that receive a real `disabled` prop.
		expect( groupReset( 'Color' ) ).toBeDisabled();
		expect( screen.getByRole( 'button', { name: 'Text' } ) ).toBeDisabled();
		expect( screen.getByLabelText( 'Radius' ) ).toBeDisabled();
		expect( screen.getByRole( 'button', { name: 'Unlink Corners' } ) ).toBeDisabled();
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

	it( 'warns using the default background when only text is set', () => {
		render( <StyleSection status={ CLASSIC_STATUS } styles={ { color: { text: '#888888' } } } inFlight={ false } onChangeStyles={ () => {} } /> );
		// Default background #f7f7f7 vs #888888 is below 4.5 too, so the warning
		// must consider defaults: expect it present.
		expect( screen.getByText( CONTRAST_WARNING, { selector: NOTICE_CONTENT } ) ).toBeInTheDocument();
	} );
} );
