/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { BylineInspectorControls } from './inspector.jsx';

// Mock InspectorControls to render children directly.
jest.mock( '@wordpress/block-editor', () => ( {
	InspectorControls: ( { children } ) => <div data-testid="inspector">{ children }</div>,
} ) );

describe( 'BylineInspectorControls', () => {
	const defaultProps = {
		attributes: {
			prefix: 'By',
			linkToAuthorArchive: true,
		},
		setAttributes: jest.fn(),
		isCustomByline: false,
	};

	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'should render prefix and link controls when not custom byline', () => {
		render( <BylineInspectorControls { ...defaultProps } /> );

		expect( screen.getByLabelText( /prefix/i ) ).toBeInTheDocument();
		expect( screen.getByLabelText( /link to author archive/i ) ).toBeInTheDocument();
	} );

	it( 'should hide controls and show message when custom byline is active', () => {
		render( <BylineInspectorControls { ...defaultProps } isCustomByline={ true } /> );

		expect( screen.queryByLabelText( /prefix/i ) ).not.toBeInTheDocument();
		expect( screen.queryByLabelText( /link to author archive/i ) ).not.toBeInTheDocument();
		expect( screen.getByText( /prefix and link settings are controlled/i ) ).toBeInTheDocument();
	} );

	it( 'should call setAttributes when prefix changes', () => {
		const setAttributes = jest.fn();
		render( <BylineInspectorControls { ...defaultProps } setAttributes={ setAttributes } /> );

		const input = screen.getByLabelText( /prefix/i );
		fireEvent.change( input, { target: { value: 'Written by' } } );

		expect( setAttributes ).toHaveBeenCalledWith( { prefix: 'Written by' } );
	} );

	it( 'should call setAttributes when link toggle changes', () => {
		const setAttributes = jest.fn();
		render( <BylineInspectorControls { ...defaultProps } setAttributes={ setAttributes } /> );

		const toggle = screen.getByLabelText( /link to author archive/i );
		fireEvent.click( toggle );

		expect( setAttributes ).toHaveBeenCalledWith( { linkToAuthorArchive: false } );
	} );
} );
