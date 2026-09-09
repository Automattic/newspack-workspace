/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import Brands from './brands';

// The list rows are their own component with their own fetches; this file is about
// which of the four load states the screen picks, not how a row looks.
jest.mock( './brand', () => ( { brand } ) => <div data-testid="brand-row">{ brand.name }</div> );

const BRAND = { id: 1, name: 'Evening Edition', meta: {} };

const renderBrands = ( props = {} ) =>
	render( <Brands brands={ [] } isFetching={ false } hasLoaded={ false } loadError="" deleteBrand={ jest.fn() } { ...props } /> );

const emptyState = () => screen.queryByRole( 'heading', { name: 'Get started with brands' } );

describe( 'Additional Brands list', () => {
	// The regression this guards: `isFetching` starts false and the fetch is kicked
	// off in an effect, so "no brands" and "not asked yet" look identical from the
	// list alone. Offering onboarding here tells a publisher with brands they have none.
	it( 'does not offer onboarding before the brands have loaded', () => {
		renderBrands( { hasLoaded: false } );

		expect( emptyState() ).not.toBeInTheDocument();
		expect( screen.getByText( 'Fetching brands…' ) ).toBeInTheDocument();
	} );

	it( 'offers onboarding once an empty list has actually loaded', () => {
		renderBrands( { hasLoaded: true } );

		expect( emptyState() ).toBeInTheDocument();
		expect( screen.queryByText( 'Fetching brands…' ) ).not.toBeInTheDocument();
	} );

	// A failed request leaves the list empty too, and an empty state would claim the
	// site has no brands when nobody managed to ask.
	it( 'reports a failed load rather than claiming there are no brands', () => {
		renderBrands( { hasLoaded: false, loadError: 'The API went away.' } );

		expect( screen.getByText( 'The API went away.' ) ).toBeInTheDocument();
		expect( emptyState() ).not.toBeInTheDocument();
	} );

	it( 'lists the brands once there are some', () => {
		renderBrands( { hasLoaded: true, brands: [ BRAND ] } );

		expect( screen.getByTestId( 'brand-row' ) ).toHaveTextContent( 'Evening Edition' );
		expect( emptyState() ).not.toBeInTheDocument();
	} );

	// Both CTAs navigate rather than submitting, so they must be anchors: a Button
	// nested in a link gives assistive tech two controls for one action.
	it( 'routes to the new-brand form from a single control', () => {
		renderBrands( { hasLoaded: true } );

		const cta = screen.getByRole( 'link', { name: 'Add Brand' } );
		expect( cta ).toHaveAttribute( 'href', '#/additional-brands/new' );
		expect( cta.querySelector( 'button' ) ).toBeNull();
	} );

	it( 'names the help link for screen readers', () => {
		renderBrands( { hasLoaded: true } );

		expect( screen.getByRole( 'link', { name: /Learn more about brands/ } ) ).toBeInTheDocument();
	} );
} );
