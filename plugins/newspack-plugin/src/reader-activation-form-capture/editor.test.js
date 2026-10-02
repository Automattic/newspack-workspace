/**
 * External dependencies
 */
import { fireEvent, render, screen } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { applyFilters } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import { addAttribute, ATTRIBUTE, CapturePanel } from './editor';

jest.mock( '@wordpress/block-editor', () => ( {
	InspectorControls: ( { children } ) => <div data-testid="inspector">{ children }</div>,
} ) );
// Stubbed for speed and so the toggle and notice are plain, queryable elements.
jest.mock( '@wordpress/components', () => ( {
	PanelBody: ( { title, children } ) => (
		<section>
			<h2>{ title }</h2>
			{ children }
		</section>
	),
	ToggleControl: ( { label, help, checked, onChange } ) => (
		<>
			<input type="checkbox" aria-label={ label } checked={ !! checked } onChange={ e => onChange( e.target.checked ) } />
			<span>{ help }</span>
		</>
	),
	Notice: ( { children } ) => <div role="alert">{ children }</div>,
} ) );

const INTEGRATIONS_URL = '/wp-admin/admin.php?page=newspack-settings#/integrations';

describe( 'form-capture editor extension', () => {
	beforeEach( () => {
		window.newspack_form_capture_editor = { active: true, integrations_url: INTEGRATIONS_URL };
	} );
	afterEach( () => {
		delete window.newspack_form_capture_editor;
	} );

	it( 'declares the attribute on the Gravity Forms block only', () => {
		const settings = { attributes: { formId: { type: 'string' } } };
		const gf = applyFilters( 'blocks.registerBlockType', settings, 'gravityforms/form' );
		expect( gf.attributes[ ATTRIBUTE ] ).toEqual( { type: 'boolean', default: false } );
		expect( gf.attributes.formId ).toEqual( { type: 'string' } );
		expect( applyFilters( 'blocks.registerBlockType', settings, 'core/paragraph' ) ).toBe( settings );
		expect( settings.attributes[ ATTRIBUTE ] ).toBeUndefined();
		expect( addAttribute( settings, 'core/paragraph' ) ).toBe( settings );
	} );

	it( 'adds the panel to the Gravity Forms block edit and to no other block', () => {
		const Edit = () => <p>edit</p>;
		const Wrapped = applyFilters( 'editor.BlockEdit', Edit );
		const { rerender } = render( <Wrapped name="gravityforms/form" attributes={ {} } setAttributes={ jest.fn() } /> );
		expect( screen.getByText( 'edit' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'heading', { name: 'Newspack' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'checkbox', { name: 'Register readers' } ) ).not.toBeChecked();

		rerender( <Wrapped name="core/paragraph" attributes={ {} } setAttributes={ jest.fn() } /> );
		expect( screen.getByText( 'edit' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'checkbox' ) ).toBeNull();
	} );

	it( 'flips the attribute through setAttributes', () => {
		const setAttributes = jest.fn();
		render( <CapturePanel checked={ false } setAttributes={ setAttributes } /> );
		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Register readers' } ) );
		expect( setAttributes ).toHaveBeenCalledWith( { [ ATTRIBUTE ]: true } );
	} );

	it( 'warns with a link to Integrations only while the integration is inactive', () => {
		window.newspack_form_capture_editor.active = false;
		const { rerender } = render( <CapturePanel checked={ true } setAttributes={ jest.fn() } /> );
		expect( screen.getByRole( 'alert' ) ).toHaveTextContent( "isn't active" );
		expect( screen.getByRole( 'link', { name: 'Check it in Integrations' } ) ).toHaveAttribute( 'href', INTEGRATIONS_URL );
		// The choice still saves, so it takes effect once the integration is on.
		expect( screen.getByRole( 'checkbox', { name: 'Register readers' } ) ).toBeChecked();

		window.newspack_form_capture_editor.active = true;
		rerender( <CapturePanel checked={ true } setAttributes={ jest.fn() } /> );
		expect( screen.queryByRole( 'alert' ) ).toBeNull();
	} );
} );
