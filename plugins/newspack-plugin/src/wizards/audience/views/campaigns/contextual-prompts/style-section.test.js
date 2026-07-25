/**
 * Contextual Prompts Style section: on a block theme it only offers the handoff
 * to the Site Editor's Styles panel, with no site-wide style controls; on a
 * classic theme it renders the site-wide style control groups.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
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

// The editor's two contrast messages, verbatim: the suggestion follows the way the
// pair already leans.
const CONTRAST_WARNING_DARKER_BACKGROUND =
	'This color combination may be hard for people to read. Try using a darker background color and/or a brighter text color.';
const CONTRAST_WARNING_BRIGHTER_BACKGROUND =
	'This color combination may be hard for people to read. Try using a brighter background color and/or a darker text color.';
// A Notice announces itself into the a11y-speak region, which duplicates its text
// in the document, so the queries below are scoped to the rendered notice.
const NOTICE_CONTENT = '.components-notice__content';

// Every group is a ToolsPanel whose header carries an options menu, named after
// the group: it offers a reset per item holding an override, plus Reset all.
const openPanelMenu = label => fireEvent.click( screen.getByRole( 'button', { name: `${ label } options` } ) );
const clickMenuItem = name => fireEvent.click( screen.getByRole( 'menuitem', { name } ) );

// The Border group's own link toggle carries the same label as the padding one,
// as it does in the editor, so padding queries are scoped to its fieldset.
const padding = () => within( document.querySelector( '.newspack-prompt-style-padding' ) );

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
	// Core's default spacing scale, trimmed: the default padding above resolves to
	// the `40` step, so the sliders open on a preset rather than a custom value.
	style_spacing_sizes: [
		{ name: 'Small', slug: '30', size: '10px' },
		{ name: 'Medium', slug: '40', size: '20px' },
		{ name: 'Large', slug: '50', size: '30px' },
	],
	site_editor_styles_url: 'https://example.test/wp-admin/site-editor.php?p=%2Fstyles',
};

describe( 'StyleSection on a classic theme', () => {
	it( 'renders control groups and no handoff button', () => {
		render( <StyleSection status={ CLASSIC_STATUS } styles={ {} } inFlight={ false } onChangeStyles={ () => {} } /> );

		expect( screen.queryByRole( 'link', { name: 'Edit Styles' } ) ).toBeNull();
		expect( screen.getByRole( 'button', { name: 'Text' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Background' } ) ).toBeInTheDocument();

		// Each group offers its resets from an options menu, not a Reset button, and
		// the radius belongs to the Border group rather than one of its own.
		[ 'Color', 'Typography', 'Padding', 'Border' ].forEach( label =>
			expect( screen.getByRole( 'button', { name: `${ label } options` } ) ).toBeInTheDocument()
		);
		expect( screen.queryByRole( 'button', { name: 'Border Radius options' } ) ).toBeNull();
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

	it( 'opens the padding as a linked axial pair, each row on its preset step', () => {
		render( <StyleSection status={ CLASSIC_STATUS } styles={ {} } inFlight={ false } onChangeStyles={ () => {} } /> );

		// The default padding arrives resolved (20px), which is the third step of the
		// scale: None, Small, Medium, Large.
		expect( screen.getByRole( 'slider', { name: 'Vertical padding' } ) ).toHaveValue( '2' );
		expect( screen.getByRole( 'slider', { name: 'Horizontal padding' } ) ).toHaveValue( '2' );
		expect( screen.queryByRole( 'slider', { name: 'Top padding' } ) ).toBeNull();
		// Each row marks the steps between the two ends, as the editor marks them.
		expect( document.querySelectorAll( '.newspack-prompt-style-padding__row' ) ).toHaveLength( 2 );
		expect( document.querySelectorAll( '.newspack-prompt-style-padding__row:first-child .components-range-control__mark' ) ).toHaveLength( 2 );
	} );

	it( 'stores a slider step as a spacing preset on both sides of the axis', () => {
		const onChangeStyles = jest.fn();
		render( <StyleSection status={ CLASSIC_STATUS } styles={ {} } inFlight={ false } onChangeStyles={ onChangeStyles } /> );

		fireEvent.change( screen.getByRole( 'slider', { name: 'Vertical padding' } ), { target: { value: '3' } } );

		expect( onChangeStyles ).toHaveBeenCalledWith( {
			spacing: { padding: { top: 'var:preset|spacing|50', bottom: 'var:preset|spacing|50' } },
		} );

		// The other axis writes its own pair, leaving the sides it does not own to
		// the default they still render with.
		fireEvent.change( screen.getByRole( 'slider', { name: 'Horizontal padding' } ), { target: { value: '1' } } );

		expect( onChangeStyles ).toHaveBeenLastCalledWith( {
			spacing: { padding: { left: 'var:preset|spacing|30', right: 'var:preset|spacing|30' } },
		} );
	} );

	it( 'stores the first step as a plain zero, the way the editor does', () => {
		const onChangeStyles = jest.fn();
		render( <StyleSection status={ CLASSIC_STATUS } styles={ {} } inFlight={ false } onChangeStyles={ onChangeStyles } /> );

		// No preset defines it, and clearing instead would only snap the row back to
		// the default padding.
		fireEvent.change( screen.getByRole( 'slider', { name: 'Vertical padding' } ), { target: { value: '0' } } );

		expect( onChangeStyles ).toHaveBeenCalledWith( { spacing: { padding: { top: '0', bottom: '0' } } } );
	} );

	it( 'unlinks the axes into one row per side', () => {
		render( <StyleSection status={ CLASSIC_STATUS } styles={ {} } inFlight={ false } onChangeStyles={ () => {} } /> );

		fireEvent.click( padding().getByRole( 'button', { name: 'Unlink sides' } ) );

		[ 'Top padding', 'Bottom padding', 'Left padding', 'Right padding' ].forEach( name =>
			expect( screen.getByRole( 'slider', { name } ) ).toBeInTheDocument()
		);
		expect( screen.queryByRole( 'slider', { name: 'Vertical padding' } ) ).toBeNull();
		expect( padding().getByRole( 'button', { name: 'Link sides' } ) ).toBeInTheDocument();
	} );

	it( 'writes one side on its own once the sides are unlinked', () => {
		const onChangeStyles = jest.fn();
		render( <StyleSection status={ CLASSIC_STATUS } styles={ {} } inFlight={ false } onChangeStyles={ onChangeStyles } /> );

		fireEvent.click( padding().getByRole( 'button', { name: 'Unlink sides' } ) );
		fireEvent.change( screen.getByRole( 'slider', { name: 'Left padding' } ), { target: { value: '1' } } );

		expect( onChangeStyles ).toHaveBeenCalledWith( { spacing: { padding: { left: 'var:preset|spacing|30' } } } );
	} );

	it( 'swaps a padding row for an input that writes a raw value', () => {
		const onChangeStyles = jest.fn();
		render( <StyleSection status={ CLASSIC_STATUS } styles={ {} } inFlight={ false } onChangeStyles={ onChangeStyles } /> );

		// One toggle per row; the vertical axis leads.
		fireEvent.click( screen.getAllByRole( 'button', { name: 'Set custom value' } )[ 0 ] );

		expect( screen.queryByRole( 'slider', { name: 'Vertical padding' } ) ).toBeNull();
		// The input starts from the step the row was on, and its unit stays in play.
		const input = screen.getByLabelText( 'Vertical padding' );
		expect( input ).toHaveValue( 20 );

		fireEvent.change( input, { target: { value: '7' } } );

		expect( onChangeStyles ).toHaveBeenCalledWith( { spacing: { padding: { top: '7px', bottom: '7px' } } } );
	} );

	it( 'starts a row in its input when the value matches no step', () => {
		const status = {
			...CLASSIC_STATUS,
			style_defaults: { ...CLASSIC_STATUS.style_defaults, spacing: { padding: { top: '13px', right: '13px', bottom: '13px', left: '13px' } } },
		};
		render( <StyleSection status={ status } styles={ {} } inFlight={ false } onChangeStyles={ () => {} } /> );

		expect( screen.queryByRole( 'slider', { name: 'Vertical padding' } ) ).toBeNull();
		expect( screen.getByLabelText( 'Vertical padding' ) ).toHaveValue( 13 );
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

		// The radius is its own item, so resetting the border leaves it behind.
		openPanelMenu( 'Border' );
		clickMenuItem( 'Reset Border' );
		expect( onChangeStyles ).toHaveBeenCalledWith( { border: { radius: '4px' } } );
	} );

	it( 'resets only the radius from the radius item', () => {
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
		clickMenuItem( 'Reset Radius' );
		expect( onChangeStyles ).toHaveBeenCalledWith( { border: { width: '1px' } } );
	} );

	it( 'clears the radius too when the whole border group is reset', () => {
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
		expect( onChangeStyles ).toHaveBeenCalledWith( {} );
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
		expect( screen.getByRole( 'slider', { name: 'Vertical padding' } ) ).toBeDisabled();
		expect( padding().getByRole( 'button', { name: 'Unlink sides' } ) ).toBeDisabled();
	} );

	it( 'offers one border radius input paired with a slider', () => {
		render( <StyleSection status={ CLASSIC_STATUS } styles={ {} } inFlight={ false } onChangeStyles={ () => {} } /> );

		// The default radius shows in both, and there are no per-corner inputs.
		expect( screen.getByLabelText( 'Radius' ) ).toHaveValue( 10 );
		expect( screen.getByRole( 'slider', { name: 'Border radius' } ) ).toHaveValue( '10' );
		expect( screen.queryByLabelText( 'Top left' ) ).toBeNull();

		// The Border group's header no longer names the radius, so its row does.
		expect( screen.getByText( 'Radius', { selector: '.components-base-control__label' } ) ).toBeInTheDocument();
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

	it( 'writes a numeric font size preset as a px string', () => {
		const onChangeStyles = jest.fn();
		const status = {
			...CLASSIC_STATUS,
			// A theme.json may declare a preset size as a bare number, and the picker
			// hands back the shape it was given.
			style_font_sizes: [
				{ name: 'Small', slug: 'small', size: 16 },
				{ name: 'Normal', slug: 'normal', size: 20 },
			],
		};
		render( <StyleSection status={ status } styles={ {} } inFlight={ false } onChangeStyles={ onChangeStyles } /> );

		fireEvent.click( screen.getByRole( 'radio', { name: 'Normal' } ) );

		expect( onChangeStyles ).toHaveBeenCalledWith( { typography: { fontSize: '20px' } } );
	} );

	it( 'warns on a low-contrast pair, suggesting a darker background under lighter text', () => {
		render(
			<StyleSection
				status={ CLASSIC_STATUS }
				styles={ { color: { background: '#777777', text: '#888888' } } }
				inFlight={ false }
				onChangeStyles={ () => {} }
			/>
		);
		// The background is the darker of the two, so the fix is to push it darker
		// still and lift the text.
		expect( screen.getByText( CONTRAST_WARNING_DARKER_BACKGROUND, { selector: NOTICE_CONTENT } ) ).toBeInTheDocument();
	} );

	it( 'warns using the default background when only text is set', () => {
		render( <StyleSection status={ CLASSIC_STATUS } styles={ { color: { text: '#888888' } } } inFlight={ false } onChangeStyles={ () => {} } /> );
		// Default background #f7f7f7 vs #888888 is below 4.5 too, so the warning
		// must consider defaults: expect it present, this time the other way around.
		expect( screen.getByText( CONTRAST_WARNING_BRIGHTER_BACKGROUND, { selector: NOTICE_CONTENT } ) ).toBeInTheDocument();
	} );

	it( 'picks the suggestion by perceived brightness, as the editor does', () => {
		render(
			<StyleSection
				status={ CLASSIC_STATUS }
				styles={ { color: { background: '#00ff00', text: '#cccccc' } } }
				inFlight={ false }
				onChangeStyles={ () => {} }
			/>
		);
		// Green reads darker than light gray by core's perceived brightness (149.7 vs
		// 204) while WCAG relative luminance calls it the lighter of the two (0.715 vs
		// 0.604), so the two metrics point the suggestion opposite ways here. The
		// editor's answer is the brightness one.
		expect( screen.getByText( CONTRAST_WARNING_DARKER_BACKGROUND, { selector: NOTICE_CONTENT } ) ).toBeInTheDocument();
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
		expect( screen.getByText( CONTRAST_WARNING_BRIGHTER_BACKGROUND, { selector: NOTICE_CONTENT } ) ).toBeInTheDocument();
	} );
} );
