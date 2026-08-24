/**
 * External dependencies.
 */
import { render, screen } from '@testing-library/react';

/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import { forwardRef, useEffect } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import withWizard from './';
import Wizard from '../wizard';
import { GlobalNoticeFill } from '../global-notices';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

// Keeps the announcement live regions, which duplicate every notice's text, out
// of the queries below.
jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

// withWizard renders the Footer whenever it is not loading, which it is not
// without required plugins.
jest.mock( '../footer', () => () => null );

// Both globals are localized onto every real wizard screen.
window.newspack_aux_data = { is_debug_mode: false };
window.newspack_urls = { support: 'https://help.newspack.com/' };

const useRaisedError = ( { setError } ) => {
	useEffect( () => {
		setError( { message: 'Something went wrong', code: 'rest_invalid_param' } );
	}, [ setError ] );
};

const region = container => container.querySelector( '.newspack-global-notices' );

const positionOf = ( container, selector ) => Array.from( container.querySelectorAll( '*' ) ).indexOf( container.querySelector( selector ) );

const SECTIONS = [ { path: '/', render: () => <div>Section</div> } ];

describe( 'withWizard', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( {} );
	} );

	it( 'renders a fill from the wrapped component in the notice region', () => {
		const Wrapped = withWizard(
			forwardRef( () => (
				<GlobalNoticeFill>
					<span>Wrapped fill</span>
				</GlobalNoticeFill>
			) )
		);
		const { container } = render( <Wrapped /> );
		expect( region( container ) ).toContainElement( screen.getByText( 'Wrapped fill' ) );
	} );

	it( 'renders its own error notice in the region', async () => {
		const Wrapped = withWizard(
			forwardRef( ( props, ref ) => {
				useRaisedError( props );
				return <div ref={ ref } />;
			} )
		);
		const { container } = render( <Wrapped /> );
		expect( await screen.findByText( 'Something went wrong' ) ).toBeInTheDocument();
		expect( region( container ) ).toContainElement( screen.getByText( 'Something went wrong' ) );
	} );

	describe( 'wrapping a component that renders a Wizard', () => {
		const renderNested = () => {
			const Wrapped = withWizard(
				forwardRef( ( props, ref ) => {
					useRaisedError( props );
					return <Wizard ref={ ref } headerText="Nested wizard" sections={ SECTIONS } />;
				} )
			);
			return render( <Wrapped /> );
		};

		it( 'renders exactly one region, below the header', async () => {
			const { container } = renderNested();
			await screen.findByText( 'Something went wrong' );

			expect( container.querySelectorAll( '.newspack-global-notices' ) ).toHaveLength( 1 );

			const header = container.querySelector( '.newspack-page__header-region' );
			const noticeRegion = container.querySelector( '.newspack-global-notices' );
			expect( positionOf( container, '.newspack-global-notices' ) ).toBeGreaterThan( positionOf( container, '.newspack-page__header-region' ) );
			expect( header.contains( noticeRegion ) ).toBe( false );
		} );

		it( 'renders the error notice raised above the Wizard inside that region', async () => {
			const { container } = renderNested();
			expect( await screen.findByText( 'Something went wrong' ) ).toBeInTheDocument();
			expect( region( container ) ).toContainElement( screen.getByText( 'Something went wrong' ) );
		} );
	} );
} );
