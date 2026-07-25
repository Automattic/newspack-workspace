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

// Every group is a ToolsPanel whose header carries an options menu, named after
// the group: it offers a reset per item holding an override, plus Reset all.
const openPanelMenu = label => fireEvent.click( screen.getByRole( 'button', { name: `${ label } options` } ) );
const clickMenuItem = name => fireEvent.click( screen.getByRole( 'menuitem', { name } ) );

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

		// Each group offers its resets from an options menu, not a Reset button.
		[ 'Color', 'Typography', 'Padding', 'Border', 'Border Radius' ].forEach( label =>
			expect( screen.getByRole( 'button', { name: `${ label } options` } ) ).toBeInTheDocument()
		);
		expect( screen.queryByRole( 'button', { name: 'Reset' } ) ).toBeNull();
	} );

	it( 'opens a color palette in a popover from a color row', () => {
		render( <StyleSection status={ CLASSIC_STATUS } styles={ {} } inFlight={ false } onChangeStyles={ () => {} } /> );

		expect( screen.queryByRole( 'listbox', { name: 'Text' } ) ).toBeNull();

		fireEvent.click( screen.getByRole( 'button', { name: 'Text' } ) );

		expect( screen.getByRole( 'listbox', { name: 'Text' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'option', { name: 'Accent' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'listbox', { name: 'Background' } ) ).toBeNull();
	} );

	it( 'offers a reset only for the item holding an override, and clears just that item', () => {
		const onChangeStyles = jest.fn();
		render(
			<StyleSection
				status={ CLASSIC_STATUS }
				styles={ { color: { background: '#123456' } } }
				inFlight={ false }
				onChangeStyles={ onChangeStyles }
			/>
		);

		openPanelMenu( 'Color' );

		// Text holds no override, so it is listed as an unset default control.
		expect( screen.queryByRole( 'menuitem', { name: 'Reset Text' } ) ).toBeNull();
		expect( screen.getByRole( 'menuitemcheckbox', { name: 'Text' } ) ).toBeInTheDocument();

		clickMenuItem( 'Reset Background' );
		expect( onChangeStyles ).toHaveBeenCalledWith( {} );
	} );

	it( 'clears every color in the group from Reset all, leaving other groups alone', () => {
		const onChangeStyles = jest.fn();
		render(
			<StyleSection
				status={ CLASSIC_STATUS }
				styles={ { color: { text: '#111111', background: '#123456' }, border: { radius: '4px' } } }
				inFlight={ false }
				onChangeStyles={ onChangeStyles }
			/>
		);

		openPanelMenu( 'Color' );
		clickMenuItem( 'Reset all' );

		// The whole object goes back, since the REST layer replaces it wholesale.
		expect( onChangeStyles ).toHaveBeenCalledWith( { border: { radius: '4px' } } );
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

		openPanelMenu( 'Padding' );
		clickMenuItem( 'Reset Padding' );
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

		// The radius is its own group, so resetting the border leaves it behind.
		openPanelMenu( 'Border' );
		clickMenuItem( 'Reset Border' );
		expect( onChangeStyles ).toHaveBeenCalledWith( { border: { radius: '4px' } } );
	} );

	it( 'keeps the border radius when the whole border group is reset', () => {
		const onChangeStyles = jest.fn();
		render(
			<StyleSection
				status={ CLASSIC_STATUS }
				styles={ { border: { radius: '4px', width: '1px' } } }
				inFlight={ false }
				onChangeStyles={ onChangeStyles }
			/>
		);

		openPanelMenu( 'Border' );
		clickMenuItem( 'Reset all' );
		expect( onChangeStyles ).toHaveBeenCalledWith( { border: { radius: '4px' } } );
	} );

	it( 'resets only the radius from the Border Radius group', () => {
		const onChangeStyles = jest.fn();
		render(
			<StyleSection
				status={ CLASSIC_STATUS }
				styles={ { border: { radius: '4px', width: '1px' } } }
				inFlight={ false }
				onChangeStyles={ onChangeStyles }
			/>
		);

		openPanelMenu( 'Border Radius' );
		clickMenuItem( 'Reset Radius' );
		expect( onChangeStyles ).toHaveBeenCalledWith( { border: { width: '1px' } } );
	} );

	it( 'disables the controls that take a disabled prop while a save is in flight', () => {
		render(
			<StyleSection status={ CLASSIC_STATUS } styles={ { color: { background: '#123456' } } } inFlight={ true } onChangeStyles={ () => {} } />
		);

		// The pickers rely on the inert wrapper, which jsdom does not honor, so this
		// covers the controls that receive a real `disabled` prop.
		expect( screen.getByRole( 'button', { name: 'Text' } ) ).toBeDisabled();
		expect( screen.getByLabelText( 'Radius' ) ).toBeDisabled();
		expect( screen.getByRole( 'slider', { name: 'Border radius' } ) ).toBeDisabled();
	} );

	it( 'offers one border radius input paired with a slider', () => {
		render( <StyleSection status={ CLASSIC_STATUS } styles={ {} } inFlight={ false } onChangeStyles={ () => {} } /> );

		// The default radius shows in both, and there are no per-corner inputs.
		expect( screen.getByLabelText( 'Radius' ) ).toHaveValue( 10 );
		expect( screen.getByRole( 'slider', { name: 'Border radius' } ) ).toHaveValue( '10' );
		expect( screen.queryByLabelText( 'Top left' ) ).toBeNull();
	} );

	it( 'writes the border radius as a single string', () => {
		const onChangeStyles = jest.fn();
		render( <StyleSection status={ CLASSIC_STATUS } styles={ {} } inFlight={ false } onChangeStyles={ onChangeStyles } /> );

		fireEvent.change( screen.getByLabelText( 'Radius' ), { target: { value: '8' } } );

		expect( onChangeStyles ).toHaveBeenCalledWith( { border: { radius: '8px' } } );

		// The slider moves the number and keeps the unit in play.
		fireEvent.change( screen.getByRole( 'slider', { name: 'Border radius' } ), { target: { value: '24' } } );

		expect( onChangeStyles ).toHaveBeenLastCalledWith( { border: { radius: '24px' } } );
	} );

	it( 'replaces a legacy per-corner radius on the first edit, showing nothing until then', () => {
		const onChangeStyles = jest.fn();
		const cornerRadius = { topLeft: '4px', topRight: '8px' };
		render(
			<StyleSection
				status={ CLASSIC_STATUS }
				styles={ { border: { radius: cornerRadius } } }
				inFlight={ false }
				onChangeStyles={ onChangeStyles }
			/>
		);

		// No single value to show, and nothing written just by rendering.
		expect( screen.getByLabelText( 'Radius' ) ).toHaveValue( null );
		expect( onChangeStyles ).not.toHaveBeenCalled();

		fireEvent.change( screen.getByLabelText( 'Radius' ), { target: { value: '12' } } );

		expect( onChangeStyles ).toHaveBeenCalledWith( { border: { radius: '12px' } } );
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

	it( 'warns using the inherited text color when only the background is set', () => {
		// The block's default design carries no text color, so the defaults stand in
		// the one the prompt inherits from the site; without it a background chosen
		// on its own would never be checked.
		const status = {
			...CLASSIC_STATUS,
			style_defaults: { ...CLASSIC_STATUS.style_defaults, color: { background: '#f7f7f7', text: '#111111' } },
		};
		render( <StyleSection status={ status } styles={ { color: { background: '#7a5c3e' } } } inFlight={ false } onChangeStyles={ () => {} } /> );
		expect( screen.getByText( CONTRAST_WARNING, { selector: NOTICE_CONTENT } ) ).toBeInTheDocument();
	} );
} );
