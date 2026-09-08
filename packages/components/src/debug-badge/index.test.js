/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import DebugBadge from '.';

describe( 'DebugBadge', () => {
	afterEach( () => {
		delete window.newspack_aux_data;
	} );

	it( 'renders the badge and its glyph in debug mode', () => {
		window.newspack_aux_data = { is_debug_mode: true };
		const { container } = render( <DebugBadge /> );
		expect( container.querySelector( '.newspack-debug-badge' ) ).toBeInTheDocument();
		expect( container.querySelector( '.newspack-debug-badge > svg' ) ).toBeInTheDocument();
	} );

	it( 'names itself for assistive tech', () => {
		window.newspack_aux_data = { is_debug_mode: true };
		render( <DebugBadge /> );
		expect( screen.getByRole( 'img', { name: 'Debug mode' } ) ).toBeInTheDocument();
	} );

	it( 'renders nothing outside debug mode', () => {
		window.newspack_aux_data = { is_debug_mode: false };
		const { container } = render( <DebugBadge /> );
		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'renders nothing when the global is absent', () => {
		const { container } = render( <DebugBadge /> );
		expect( container ).toBeEmptyDOMElement();
	} );
} );
