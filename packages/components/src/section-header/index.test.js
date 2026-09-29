/**
 * WordPress dependencies
 */
import { envelope } from '@wordpress/icons';

/**
 * External dependencies
 */
import { render } from '@testing-library/react';

/**
 * Internal dependencies
 */
import SectionHeader from '.';

describe( 'SectionHeader size', () => {
	it( 'renders a 48px icon and no small modifier by default', () => {
		const { container } = render( <SectionHeader title="Get started" icon={ envelope } /> );
		expect( container.querySelector( '.newspack-section-header--small' ) ).not.toBeInTheDocument();
		expect( container.querySelector( '.newspack-section-header__icon svg' ) ).toHaveAttribute( 'width', '48' );
	} );

	it( 'renders a 24px icon and the small modifier at size="small"', () => {
		const { container } = render( <SectionHeader title="Get started" icon={ envelope } size="small" /> );
		expect( container.querySelector( '.newspack-section-header--small' ) ).toBeInTheDocument();
		expect( container.querySelector( '.newspack-section-header__icon svg' ) ).toHaveAttribute( 'width', '24' );
	} );
} );
